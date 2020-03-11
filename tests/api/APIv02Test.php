<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class APIv02Test extends TestCase {
	private $http;

	protected function setUp() : void {
		$this->http = new \GuzzleHttp\Client([
			'base_uri' => 'http://localhost:8080/index.php/apps/notes/api/v0.2/',
			'auth' => ['test', 'test'],
			'http_errors' => false,
		]);
	}


	// TODO move private methods in new parent class


	private function checkResponse(
		\GuzzleHttp\Psr7\Response $response,
		string $message,
		int $statusExp,
		string $contentTypeExp = 'application/json; charset=utf-8'
	) {
		$this->assertEquals($statusExp, $response->getStatusCode(), $message.': Response status code');
		$this->assertTrue($response->hasHeader('Content-Type'), $message.': Response has content-type header');
		$this->assertEquals(
			$contentTypeExp,
			$response->getHeaderLine('Content-Type'),
			$message.': Response content type'
		);
	}

	private function checkGetReferenceNotes(
		array $refNotes,
		string $message,
		bool $expectEmpty=false,
		?int $pruneBefore=null
	) : void {
		$messagePrefix = 'Check reference notes '.$message;
		$response = $this->http->request('GET', 'notes' . ($pruneBefore===null ? '' : '?pruneBefore='.$pruneBefore));
		$this->checkResponse($response, $messagePrefix, 200);
		$notes = json_decode($response->getBody()->getContents());
		$notesMap = self::getNotesIdMap($notes);
		$this->assertEquals(count($refNotes), count($notes), $messagePrefix.': Number of notes');
		foreach($refNotes as $refNote) {
			$this->checkReferenceNote($refNote, $notesMap, $expectEmpty, $messagePrefix);
		}
	}

	private function checkReferenceNote(object $refNote, array $notes, bool $expectEmpty, string $messagePrefix) : void {
		$this->assertTrue(array_key_exists($refNote->id, $notes), $messagePrefix.': Reference note '.$refNote->title.' exists');
		$note = $notes[$refNote->id];
		if($expectEmpty) {
			$this->assertEquals(1, count(get_object_vars($note)), $messagePrefix.': Number of properties (reference note: '.$refNote->title.')');
			$this->assertTrue(property_exists($note, 'id'), $messagePrefix.': Note has property id (reference note: '.$refNote->title.')');
			$this->assertEquals($refNote->id, $note->id, $messagePrefix.': ID of note (reference note: '.$refNote->title.')');
		} else {
			foreach(get_object_vars($refNote) as $key => $val) {
				$this->assertTrue(property_exists($note, $key), $messagePrefix.': Note has property '.$key.' (reference note: '.$refNote->title.')');
				$this->assertEquals($refNote->$key, $note->$key, $messagePrefix.': Property '.$key.' (reference note: '.$refNote->title.')');
			}
		}
	}

	private static function getNotesIdMap(array $notes) : array {
		$map = [];
		foreach($notes as $note) {
			$map[$note->id] = $note;
		}
		return $map;
	}

	private function createNote(object $note, string $title) {
		$response = $this->http->request('POST', 'notes');
		$this->checkResponse($response, 'Create '.$title, 200);
		return json_decode($response->getBody()->getContents());

	}

	public function testCheckForReferenceNotes() : array {
		$response = $this->http->request('GET', 'notes');
		$this->checkResponse($response, 'Get existing notes', 200);
		$notes = json_decode($response->getBody()->getContents());
		if(count($notes) == 0) {
			// TODO move this to bootstrap file and switch to direct save in filesystem
			$notes[] = $this->createNote((object)[
				'content' => "First test note\nThis is some demo text.",
			], 'First test note');
			$notes[] = $this->createNote((object)[
				'content' => "Second test note\nThis is again some demo text.",
				'category' => 'Test',
				'modified' => mktime(1, 1, 1997),
				'favorite' => true,
			], 'Second test note');
		}
		return $notes;
	}

	/** @depends testCheckForReferenceNotes */
	public function testGetNotesWithEtag(array $refNotes) : void {
		$response1 = $this->http->request('GET', 'notes');
		$this->checkResponse($response1, 'Initial response', 200);
		$this->assertTrue($response1->hasHeader('ETag'), 'Initial response has ETag header');
		$etag = $response1->getHeaderLine('ETag');
		$this->assertRegExp('/^"[[:alnum:]]{32}"$/', $etag, 'ETag format');

		// Test If-None-Match with ETag
		$response2 = $this->http->request('GET', 'notes', [ 'headers' => [ 'If-None-Match' => $etag ] ]);
		$this->checkResponse($response2, 'ETag response', 304);
		$this->assertEquals('', $response2->getBody(), 'ETag response body');
	}

	/** @depends testCheckForReferenceNotes */
	public function testGetNotesWithPruneBefore(array $refNotes) : void {
		sleep(1); // wait for 'Last-Modified' to be >= Last-change + 1
		$response1 = $this->http->request('GET', 'notes');
		$this->checkResponse($response1, 'Initial response', 200);
		$this->assertTrue($response1->hasHeader('Last-Modified'), 'Initial response has Last-Modified header');
		$lastModified = $response1->getHeaderLine('Last-Modified');
		$dt = \DateTime::createFromFormat(\DateTime::RFC2822, $lastModified);
		$this->assertInstanceOf(\DateTime::class, $dt);

		$this->checkGetReferenceNotes($refNotes, 'pruneBefore with Last-Modified', true, $dt->getTimestamp());
		$this->checkGetReferenceNotes($refNotes, 'pruneBefore with 1', false, 1);
		$this->checkGetReferenceNotes($refNotes, 'pruneBefore with PHP_INT_MAX (32bit)', true, 2147483647); // 2038-01-19 03:14:07
		$this->checkGetReferenceNotes($refNotes, 'pruneBefore with PHP_INT_MAX (64bit)', true, 9223372036854775807);
	}

	/** @depends testCheckForReferenceNotes */
	public function testGetNonExistingNoteFails(array $refNotes) : void {
		$response = $this->http->request('GET', 'notes/1');
		$this->assertEquals(404, $response->getStatusCode());
	}

	/** @depends testCheckForReferenceNotes */
	public function testCreateNote(array $refNotes) : void {
		// $this->checkGetReferenceNotes($refNotes, 'before');
		// TODO create note with random title
		// TODO checkGetReferenceNotes with created note
		// TODO delete note
		// $this->checkGetReferenceNotes($refNotes, 'after');
	}

	/** @depends testCheckForReferenceNotes */
	public function testUpdateNote(array $refNotes) : void {
		// $this->checkGetReferenceNotes($refNotes, 'before');
		// TODO update note
		// TODO checkGetReferenceNotes with updated note
		// TODO update note (undo changes)
		// $this->checkGetReferenceNotes($refNotes, 'after');
	}
}

