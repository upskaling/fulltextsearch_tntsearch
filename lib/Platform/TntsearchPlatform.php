<?php

declare(strict_types=1);

namespace OCA\Fulltextsearch_Tntsearch\Platform;

use OC\FullTextSearch\Model\DocumentAccess;
use OC\FullTextSearch\Model\IndexDocument;
use OCP\FullTextSearch\IFullTextSearchPlatform;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IRunner;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\IConfig;
use PDO;
use PDOException;
use TeamTNT\TNTSearch\Engines\SqliteEngine;
use TeamTNT\TNTSearch\TNTSearch;

class TntsearchPlatform implements IFullTextSearchPlatform {
	private const INDEX_NAME = 'tntsearch_provider.index';

	private IRunner $runner;
	private TNTSearch $tnt;
	private ?SqliteEngine $indexer = null;
	private PDO $pdo;

	public function __construct(
		private IConfig $config,
	) {
	}


	public function getId(): string {
		return 'tntsearch';
	}

	public function getName(): string {
		return 'Tntsearch';
	}

	public function getConfiguration(): array {
		return [];
	}

	public function setRunner(IRunner $runner): void {
		$this->runner = $runner;
	}

	public function loadPlatform(): void {

		$datadirectory = $this->config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data');

		$sourcePath = $datadirectory . '/tntsearch/indexes';
		$this->createDirectory($sourcePath);

		$sqlPath = $sourcePath . '/' . self::INDEX_NAME . '.storage';
		$this->pdo = new PDO('sqlite:' . $sqlPath);
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

		// create table if not exists
		$sql = "CREATE TABLE IF NOT EXISTS tntsearch_provider (
			'id' INTEGER PRIMARY KEY AUTOINCREMENT,
			'path' TEXT,
			'title' TEXT,
			'content' TEXT,
			'provider_id' TEXT,
			'source' TEXT,
			'hash' TEXT,
			'access_ownerId' TEXT,
			'access_users' JSON,
			'access_groups' JSON
		)";
		$this->pdo->exec($sql);

		$this->tnt = new TNTSearch;
		$this->tnt->loadConfig([
			'driver' => 'sqlite',
			'database' => $sqlPath,
			'storage' => $sourcePath
		]);
	}

	public function testPlatform(): bool {
		return true;
	}

	public function initializeIndex(): void {
		$this->getIndexer();
	}

	public function resetIndex(string $providerId): void {
		$sql = 'DELETE FROM tntsearch_provider
				WHERE provider_id = :provider_id';
		$stmt = $this->pdo->prepare($sql);
		$stmt->bindParam(':provider_id', $providerId);
		$stmt->execute();

		$this->loadPlatform();
		$this->getIndexer();
	}

	public function deleteIndexes(array $indexes): void {
		$this->getIndexer();
	}

	public function indexDocument(IIndexDocument $document): IIndex {
		$document->initHash();
		$documentI = [
			'id' => $document->getId(),
			'title' => $document->getTitle(),
			'content' => $this->isContentBase64($document),
			'provider_id' => $document->getProviderId(),
			'access_ownerId' => $document->getAccess()->getOwnerId(),
			'access_users' => json_encode($document->getAccess()->getUsers()),
			'access_groups' => json_encode($document->getAccess()->getGroups()),
			'source' => $document->getSource(),
			'hash' => $document->getHash(),
		];

		$sql = 'SELECT id FROM tntsearch_provider WHERE path = :path';
		$stmt = $this->pdo->prepare($sql);
		$stmt->bindParam(':path', $documentI['id']);
		$stmt->execute();
		$doc = $stmt->fetchAll();

		if ($doc) {
			$this->pdo->beginTransaction();
			$stmt = $this->pdo->prepare('UPDATE
						tntsearch_provider
					SET
						path = :path,
						title = :title,
						content = :content,
						provider_id = :provider_id,
						source = :source,
						hash = :hash,
						access_ownerId = :access_ownerId,
						access_users = :access_users,
						access_groups = :access_groups
					WHERE
						id = :id');
			$stmt->bindParam(':path', $documentI['id']);
			$stmt->bindParam(':title', $documentI['title']);
			$stmt->bindParam(':content', $documentI['content']);
			$stmt->bindParam(':provider_id', $documentI['provider_id']);
			$stmt->bindParam(':source', $documentI['source']);
			$stmt->bindParam(':hash', $documentI['hash']);
			$stmt->bindParam(':access_ownerId', $documentI['access_ownerId']);
			$stmt->bindParam(':id', $doc[0]['id']);
			$stmt->bindParam(':access_users', $documentI['access_users']);
			$stmt->bindParam(':access_groups', $documentI['access_groups']);
			try {
				$stmt->execute();
				$this->pdo->commit();
			} catch (PDOException $e) {
				$this->pdo->rollBack();
				throw $e;
			}
		} else {
			$this->pdo->beginTransaction();
			$stmt = $this->pdo->prepare('INSERT
				INTO
					tntsearch_provider ( path,
					title,
					content,
					provider_id,
					source,
					hash,
					access_ownerId,
					access_users,
					access_groups)
				VALUES (:path,
				:title,
				:content,
				:provider_id,
				:source,
				:hash,
				:access_ownerId,
				:access_users,
				:access_groups)');
			$stmt->bindParam(':path', $documentI['id']);
			$stmt->bindParam(':title', $documentI['title']);
			$stmt->bindParam(':content', $documentI['content']);
			$stmt->bindParam(':provider_id', $documentI['provider_id']);
			$stmt->bindParam(':source', $documentI['source']);
			$stmt->bindParam(':hash', $documentI['hash']);
			$stmt->bindParam(':access_ownerId', $documentI['access_ownerId']);
			$stmt->bindParam(':access_users', $documentI['access_users']);
			$stmt->bindParam(':access_groups', $documentI['access_groups']);
			try {
				$stmt->execute();
				$this->pdo->commit();
			} catch (PDOException $e) {
				$this->pdo->rollBack();
				throw $e;
			}
		}

		$this->getIndexer();
		$this->indexer->run();

		return $document->getIndex();

	}

	private function isContentBase64(IIndexDocument $document): string {
		if (get_class($document) === "OCA\Files_FullTextSearch\Model\FilesDocument") {
			if (!in_array($document->getMimetype(), ['text/markdown'])) {
				return '';
			}
		}
		$content = $document->getContent();
		if ($content === '') {
			return '';
		}
		// test mime type
		if ($document->isContentEncoded() === IIndexDocument::ENCODED_BASE64) {
			return base64_decode($content);
		}
		return $content;

	}

	public function searchRequest(
		ISearchResult $result,
		IDocumentAccess $access,
	): void {
		$request = $result->getRequest();
		$search = $request->getSearch();
		// $providerId = $request->getProviders();
		$this->tnt->selectIndex(self::INDEX_NAME);
		// $this->tnt->fuzziness(true);
		$resultMY = $this->tnt->search($search, $request->getSize() + 1);
		$result->setRawResult(json_encode($resultMY));

		$time = explode(' ', $resultMY['execution_time']);
		$result->setTime((int)$time[0]);

		$result->setTotal((int)$resultMY['hits']);

		foreach ($resultMY['ids'] as $value) {
			$stmt = $this->pdo->prepare('SELECT
						id,
						path,
						title,
						content,
						provider_id,
						source,
						hash,
						access_ownerId,
						access_users,
						access_groups
					FROM
						tntsearch_provider
					WHERE
						id = :id');
			$stmt->bindParam(':id', $value);
			$stmt->execute();
			$resultDB = $stmt->fetchAll()[0];

			if ($this->isDocumentAccessible($resultDB, $access)) {
				$name = $resultDB['path'];
				$docScores = $resultMY['docScores'][$value];
				$document = $this->getDocument('files', $name);
				$document->setScore((string)$docScores);

				$document->setExcerpts($this->parseSearchEntryExcerpts($document, $search));

				$result->addDocument($document);
			}

		}
	}

	private function parseSearchEntryExcerpts(IndexDocument $document, string $search): array {
		$excerpts = [];
		$content = $document->getContent();
		$contentLength = mb_strlen($content);
		$searchLength = mb_strlen($search);

		$offset = 0;  // Position de départ pour la recherche de la prochaine occurrence

		// Boucle pour trouver toutes les occurrences de la chaîne recherchée
		while (($idx = mb_stripos($content, $search, $offset)) !== false) {
			// Calculer les limites pour l'extrait
			$start = max(0, $idx - 20);
			$end = min($contentLength, $idx + $searchLength + 20);

			// Récupérer l'extrait avec une marge de 20 caractères de chaque côté
			$excerpt = mb_substr($content, $start, $end - $start);

			// Ajouter l'extrait et la source au tableau des extraits
			$excerpts[] = [
				'source' => $document->getSource(),
				'excerpt' => $excerpt
			];

			// Mettre à jour l'offset pour continuer la recherche après cette occurrence
			$offset = $idx + $searchLength;
		}

		return $excerpts;
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function isDocumentAccessible(array $document, IDocumentAccess $access): bool {
		if ($access->getViewerId() === $document['access_ownerId']) {
			return true;
		}
		$accessUsers = json_decode($document['access_users'], true);

		if (in_array($access->getViewerId(), $accessUsers)) {
			return true;
		}

		$accessGroups = json_decode($document['access_groups'], true);

		foreach ($access->getGroups() as $group) {
			if (in_array($group, $accessGroups)) {
				return true;
			}
		}

		return false;
	}


	public function getDocument(
		string $providerId,
		string $documentId,
	): IndexDocument {
		$this->tnt->selectIndex(self::INDEX_NAME);

		$stmt = $this->pdo->prepare('SELECT id, path, title, content, provider_id, source, hash, access_ownerId, access_users, access_groups FROM tntsearch_provider WHERE path = :path');
		$stmt->bindParam(':path', $documentId);
		$stmt->execute();
		$result = $stmt->fetchAll()[0] ?? null;

		$document = $this->newMethod($result, $providerId, $documentId);

		return $document;
	}

	private function newMethod(
		$index,
		?string $providerId,
		?string $documentId,
	): IndexDocument {
		if ($providerId === null) {
			$providerId = $index['providerId'];
		}
		if ($documentId === null) {
			$documentId = $index['id'];
		}

		$document = new IndexDocument($providerId, $documentId);

		$document->setContent($index['content']);
		$document->setHash($index['hash']);
		$document->setTitle($index['title']);
		$document->setLink($index['title']);

		$documentAccess = new DocumentAccess($index['access_ownerId']);
		$document->setAccess($documentAccess);

		return $document;
	}

	private function getIndexer(): SqliteEngine {
		if ($this->indexer === null) {
			$this->indexer = $this->tnt->createIndex(self::INDEX_NAME);
			$this->indexer->query('SELECT id, path, title, content, access_ownerId FROM tntsearch_provider');
		}
		return $this->indexer;
	}

	private function createDirectory(string $path): void {
		if (!file_exists($path)) {
			mkdir($path, 0775, true);
		}
	}
}
