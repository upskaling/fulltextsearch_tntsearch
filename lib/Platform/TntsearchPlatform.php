<?php

declare(strict_types=1);

namespace OCA\Fulltextsearch_Tntsearch\Platform;

use Exception;
use OC\FullTextSearch\Model\DocumentAccess;
use OC\FullTextSearch\Model\IndexDocument;
use OCA\Fulltextsearch_Tntsearch\Service\ConfigService;
use OCP\FullTextSearch\IFullTextSearchPlatform;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IRunner;
use OCP\FullTextSearch\Model\ISearchResult;
use PDO;
use PDOStatement;
use Psr\Log\LoggerInterface;
use TeamTNT\TNTSearch\Indexer\TNTIndexer;
use TeamTNT\TNTSearch\TNTSearch;

class TntsearchPlatform implements IFullTextSearchPlatform {

	private const INDEX_NAME = 'tntsearch_provider.index';

	private ?IRunner $runner = null;
	private ?TNTSearch $tnt = null;
	private ?TNTIndexer $indexer = null;
	private ?PDO $pdo = null;
	private string $indexPath = '';

	public function __construct(
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}


	public function getId(): string {
		return 'tntsearch';
	}


	public function getName(): string {
		return 'Tntsearch';
	}


	public function getConfiguration(): array {
		$result = $this->configService->getConfig();
		$result['index_path'] = $this->indexPath;
		return $result;
	}


	public function setRunner(IRunner $runner): void {
		$this->runner = $runner;
	}


	public function loadPlatform(): void {
		$config = $this->configService->getConfig();
		$this->indexPath = $config['index_path'];

		if (empty($this->indexPath)) {
			$dataDir = $this->configService->getAppValue('datadirectory', '');
			$this->indexPath = !empty($dataDir) ? $dataDir . '/tntsearch/indexes' : '/var/www/html/data/tntsearch/indexes';
		}

		$this->createDirectory($this->indexPath);

		$sqlPath = $this->indexPath . '/' . self::INDEX_NAME . '.storage';
		$this->pdo = new PDO('sqlite:' . $sqlPath);
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

		$this->createTableIfNotExists();

		$this->tnt = new TNTSearch;
		$this->tnt->loadConfig([
			'driver' => 'sqlite',
			'database' => $sqlPath,
			'storage' => $this->indexPath
		]);

		$this->logger->debug('TntsearchPlatform loaded', ['index_path' => $this->indexPath]);
	}


	public function testPlatform(): bool {
		try {
			$this->pdo->query('SELECT 1');
			return true;
		} catch (Exception $e) {
			$this->logger->error('Tntsearch platform test failed', ['exception' => $e]);
			return false;
		}
	}


	public function initializeIndex(): void {
		$this->getIndexer();
	}


	public function resetIndex(string $providerId): void {
		try {
			if ($providerId === 'all') {
				$sql = 'DELETE FROM tntsearch_provider';
				$this->pdo->exec($sql);
			} else {
				$sql = 'DELETE FROM tntsearch_provider WHERE provider_id = :provider_id';
				$stmt = $this->pdo->prepare($sql);
				$stmt->bindValue(':provider_id', $providerId);
				$stmt->execute();
			}
			$this->indexer = null;
			$this->getIndexer();
			$this->logger->info('Index reset', ['provider_id' => $providerId]);
		} catch (Exception $e) {
			$this->logger->error('Failed to reset index', ['provider_id' => $providerId, 'exception' => $e]);
		}
	}


	public function deleteIndexes(array $indexes): void {
		foreach ($indexes as $index) {
			try {
				$sql = 'DELETE FROM tntsearch_provider WHERE id = :id';
				$stmt = $this->pdo->prepare($sql);
				$stmt->bindValue(':id', $index->getDocumentId());
				$stmt->execute();
				$this->updateNewIndexResult($index, 'index deleted', 'success', IRunner::RESULT_TYPE_SUCCESS);
			} catch (Exception $e) {
				$this->updateNewIndexResult($index, 'index not deleted', $e->getMessage(), IRunner::RESULT_TYPE_WARNING);
				$this->logger->warning('Failed to delete index', ['document_id' => $index->getDocumentId(), 'exception' => $e]);
			}
		}
		$this->indexer = null;
		$this->getIndexer();
	}


	public function indexDocument(IIndexDocument $document): IIndex {
		$document->initHash();

		try {
			$documentData = $this->prepareDocumentData($document);

			$existingDoc = $this->findExistingDocument($document->getId());

			if ($existingDoc) {
				$this->updateDocument($existingDoc['id'], $documentData);
			} else {
				$this->insertDocument($documentData);
			}

			$this->updateSearchIndex();

			$this->updateNewIndexResult(
				$document->getIndex(),
				json_encode(['hash' => $document->getHash()]),
				'ok',
				IRunner::RESULT_TYPE_SUCCESS
			);

			return $document->getIndex();
		} catch (Exception $e) {
			$this->manageIndexError($document, $e);
			return $document->getIndex();
		}
	}


	private function prepareDocumentData(IIndexDocument $document): array {
		return [
			'id' => $document->getId(),
			'title' => $document->getTitle(),
			'content' => $this->getDocumentContent($document),
			'provider_id' => $document->getProviderId(),
			'access_ownerId' => $document->getAccess()->getOwnerId(),
			'access_users' => json_encode($document->getAccess()->getUsers()),
			'access_groups' => json_encode($document->getAccess()->getGroups()),
			'source' => $document->getSource(),
			'hash' => $document->getHash(),
		];
	}


	private function getDocumentContent(IIndexDocument $document): string {
		if (method_exists($document, 'getMimetype') && !in_array($document->getMimetype(), ['text/markdown'])) {
			return '';
		}

		$content = $document->getContent();
		if (empty($content)) {
			return '';
		}

		if ($document->isContentEncoded() === IIndexDocument::ENCODED_BASE64) {
			return base64_decode($content);
		}

		return $content;
	}


	private function findExistingDocument(string $documentId): ?array {
		$sql = 'SELECT id FROM tntsearch_provider WHERE path = :path';
		$stmt = $this->pdo->prepare($sql);
		$stmt->bindValue(':path', $documentId);
		$stmt->execute();
		return $stmt->fetch() ?: null;
	}


	private function updateDocument(int $id, array $data): void {
		$sql = 'UPDATE tntsearch_provider SET 
			path = :path,
			title = :title,
			content = :content,
			provider_id = :provider_id,
			source = :source,
			hash = :hash,
			access_ownerId = :access_ownerId,
			access_users = :access_users,
			access_groups = :access_groups
			WHERE id = :id';

		$stmt = $this->pdo->prepare($sql);
		$this->bindDocumentValues($stmt, $data);
		$stmt->bindValue(':id', $id, PDO::PARAM_INT);
		$stmt->execute();
	}


	private function insertDocument(array $data): void {
		$sql = 'INSERT INTO tntsearch_provider (
			path, title, content, provider_id, source, hash, 
			access_ownerId, access_users, access_groups
		) VALUES (
			:path, :title, :content, :provider_id, :source, :hash,
			:access_ownerId, :access_users, :access_groups
		)';

		$stmt = $this->pdo->prepare($sql);
		$this->bindDocumentValues($stmt, $data);
		$stmt->execute();
	}


	private function bindDocumentValues(PDOStatement $stmt, array $data): void {
		$stmt->bindValue(':path', $data['id']);
		$stmt->bindValue(':title', $data['title']);
		$stmt->bindValue(':content', $data['content']);
		$stmt->bindValue(':provider_id', $data['provider_id']);
		$stmt->bindValue(':source', $data['source']);
		$stmt->bindValue(':hash', $data['hash']);
		$stmt->bindValue(':access_ownerId', $data['access_ownerId']);
		$stmt->bindValue(':access_users', $data['access_users']);
		$stmt->bindValue(':access_groups', $data['access_groups']);
	}


	private function updateSearchIndex(): void {
		$sqlPath = $this->indexPath . '/' . self::INDEX_NAME . '.storage';
		$tnt = new TNTSearch;
		$tnt->loadConfig([
			'driver' => 'sqlite',
			'database' => $sqlPath,
			'storage' => $this->indexPath,
			'dbh' => $this->pdo,
		]);
		$indexer = $tnt->createIndex(self::INDEX_NAME, true);
		$indexer->setDatabaseHandle($this->pdo);
		$indexer->query('SELECT id, path, title, content, access_ownerId FROM tntsearch_provider');
		$indexer->run();
	}


	public function searchRequest(ISearchResult $result, IDocumentAccess $access): void {
		$request = $result->getRequest();
		$search = trim($request->getSearch());

		if (empty($search)) {
			$result->setTotal(0);
			return;
		}

		$this->tnt->fuzziness($this->configService->getAppValueBool('fuzziness', false));
		$this->tnt->selectIndex(self::INDEX_NAME);

		try {
			$searchResults = $this->tnt->search($search, $request->getSize() + 1);

			$result->setRawResult(json_encode($searchResults));

			$time = explode(' ', $searchResults['execution_time']);
			$result->setTime((int)($time[0] ?? 0));
			$result->setTotal((int)$searchResults['hits']);

			$this->processSearchResults($searchResults, $result, $access, $search);
		} catch (Exception $e) {
			$this->logger->error('Search failed', ['search' => $search, 'exception' => $e]);
			$result->setTotal(0);
		}
	}


	private function processSearchResults(array $searchResults, ISearchResult $result, IDocumentAccess $access, string $search): void {
		$negativeKeywords = $this->extractKeywords($search, '/(?:^|\s)-(\w+)/');
		$positiveKeywords = $this->extractKeywords($search, '/(?:^|\s)\+(\w+)/');
		$phraseSearch = $this->extractPhraseSearch($search);

		foreach ($searchResults['ids'] as $index => $documentId) {
			try {
				$document = $this->getDocumentFromDatabase($documentId);
				if (!$document) {
					continue;
				}

				if (!$this->isDocumentAccessible($document, $access)) {
					continue;
				}

				if (!$this->passesFilters($document, $negativeKeywords, $positiveKeywords, $phraseSearch)) {
					continue;
				}

				$searchDocument = $this->createSearchDocument($document, $searchResults, $index, $search);
				/** @psalm-suppress InvalidArgument */
				$result->addDocument($searchDocument);
			} catch (Exception $e) {
				$this->logger->warning('Error processing search result', ['document_id' => $documentId, 'exception' => $e]);
			}
		}
	}


	private function extractKeywords(string $search, string $pattern): array {
		preg_match_all($pattern, $search, $matches);
		return $matches[1] ?? [];
	}


	private function extractPhraseSearch(string $search): ?string {
		if (preg_match('/"([^"]+)"/', $search, $matches)) {
			return $matches[1];
		}
		return null;
	}


	private function getDocumentFromDatabase(int $documentId): ?array {
		$sql = 'SELECT * FROM tntsearch_provider WHERE id = :id';
		$stmt = $this->pdo->prepare($sql);
		$stmt->bindValue(':id', $documentId, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetch() ?: null;
	}


	private function passesFilters(array $document, array $negativeKeywords, array $positiveKeywords, ?string $phraseSearch): bool {
		$content = $document['content'];

		if ($phraseSearch !== null && mb_stripos($content, $phraseSearch) === false) {
			return false;
		}

		foreach ($negativeKeywords as $keyword) {
			if (mb_stripos($content, $keyword) !== false) {
				return false;
			}
		}

		foreach ($positiveKeywords as $keyword) {
			if (mb_stripos($content, $keyword) === false) {
				return false;
			}
		}

		return true;
	}


	private function isDocumentAccessible(array $document, IDocumentAccess $access): bool {
		if ($access->getViewerId() === $document['access_ownerId']) {
			return true;
		}

		$accessUsers = json_decode($document['access_users'], true);
		if (is_array($accessUsers) && in_array($access->getViewerId(), $accessUsers, true)) {
			return true;
		}

		$accessGroups = json_decode($document['access_groups'], true);
		if (is_array($accessGroups)) {
			foreach ($access->getGroups() as $group) {
				if (in_array($group, $accessGroups, true)) {
					return true;
				}
			}
		}

		return false;
	}


	private function createSearchDocument(array $document, array $searchResults, int $index, string $search): IndexDocument {
		$doc = new IndexDocument($document['provider_id'], $document['path']);

		$doc->setTitle($document['title']);
		$doc->setContent($document['content']);
		$doc->setHash($document['hash']);
		$doc->setSource($document['source']);

		$docAccess = new DocumentAccess($document['access_ownerId']);
		$docAccess->setUsers(json_decode($document['access_users'], true) ?? []);
		$docAccess->setGroups(json_decode($document['access_groups'], true) ?? []);
		$doc->setAccess($docAccess);

		$score = $searchResults['docScores'][$document['id']] ?? 0;
		$doc->setScore((string)$score);

		$excerpts = $this->generateExcerpts($doc, $search);
		$doc->setExcerpts($excerpts);

		return $doc;
	}


	private function generateExcerpts(IndexDocument $document, string $search): array {
		$excerpts = [];
		$content = $document->getContent();
		$contentLength = mb_strlen($content);
		$searchLength = mb_strlen($search);

		if ($contentLength === 0 || $searchLength === 0) {
			return $excerpts;
		}

		$offset = 0;
		$maxExcerpts = 5;

		while (count($excerpts) < $maxExcerpts && ($idx = mb_stripos($content, $search, $offset)) !== false) {
			$start = max(0, $idx - 40);
			$end = min($contentLength, $idx + $searchLength + 40);

			$excerpt = mb_substr($content, $start, $end - $start);
			if ($start > 0) {
				$excerpt = '...' . $excerpt;
			}
			if ($end < $contentLength) {
				$excerpt .= '...';
			}

			$excerpts[] = [
				'source' => $document->getSource(),
				'excerpt' => $excerpt
			];

			$offset = $idx + $searchLength;
		}

		return $excerpts;
	}


	public function getDocument(string $providerId, string $documentId): IIndexDocument {
		$sql = 'SELECT * FROM tntsearch_provider WHERE path = :path';
		$stmt = $this->pdo->prepare($sql);
		$stmt->bindValue(':path', $documentId);
		$stmt->execute();
		$result = $stmt->fetch();

		if (!$result) {
			throw new Exception('Document not found: ' . $documentId);
		}

		return $this->createDocumentFromResult($result, $providerId, $documentId);
	}


	private function createDocumentFromResult(array $result, string $providerId, string $documentId): IIndexDocument {
		$document = new IndexDocument($providerId, $documentId);

		$document->setContent($result['content']);
		$document->setHash($result['hash']);
		$document->setTitle($result['title']);
		$document->setSource($result['source']);

		$documentAccess = new DocumentAccess($result['access_ownerId']);
		$documentAccess->setUsers(json_decode($result['access_users'], true) ?? []);
		$documentAccess->setGroups(json_decode($result['access_groups'], true) ?? []);
		$document->setAccess($documentAccess);

		/** @var IIndexDocument $document */
		return $document;
	}


	private function getIndexer(): TNTIndexer {
		if ($this->indexer === null) {
			$this->tnt->selectIndex(self::INDEX_NAME);
			$indexer = new TNTIndexer($this->tnt->engine);
			$indexer->loadConfig($this->tnt->config);
			$indexer->setDatabaseHandle($this->pdo);
			$indexer->query('SELECT id, path, title, content, access_ownerId FROM tntsearch_provider');
			$this->indexer = $indexer;
		}

		return $this->indexer;
	}


	private function createTableIfNotExists(): void {
		$sql = 'CREATE TABLE IF NOT EXISTS tntsearch_provider (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			path TEXT,
			title TEXT,
			content TEXT,
			provider_id TEXT,
			source TEXT,
			hash TEXT,
			access_ownerId TEXT,
			access_users JSON,
			access_groups JSON
		)';
		$this->pdo->exec($sql);
	}


	private function createDirectory(string $path): void {
		if (!file_exists($path)) {
			mkdir($path, 0775, true);
		}
	}


	private function updateRunnerAction(string $action, bool $force = false): void {
		if ($this->runner === null) {
			return;
		}

		$this->runner->updateAction($action, $force);
	}


	private function updateNewIndexResult(IIndex $index, string $message, string $status, int $type): void {
		if ($this->runner === null) {
			return;
		}

		$this->runner->newIndexResult($index, $message, $status, $type);
	}


	private function updateNewIndexError(IIndex $index, string $message, string $exception, int $sev): void {
		if ($this->runner === null) {
			return;
		}

		$this->runner->newIndexError($index, $message, $exception, $sev);
	}


	private function manageIndexError(IIndexDocument $document, Exception $e): void {
		$message = $e->getMessage();

		$this->updateNewIndexResult(
			$document->getIndex(),
			'',
			'fail',
			IRunner::RESULT_TYPE_FAIL
		);

		$this->updateNewIndexError(
			$document->getIndex(),
			$message,
			get_class($e),
			IIndex::ERROR_SEV_3
		);

		$this->logger->error('Index error', [
			'document_id' => $document->getId(),
			'provider_id' => $document->getProviderId(),
			'exception' => $e
		]);
	}
}
