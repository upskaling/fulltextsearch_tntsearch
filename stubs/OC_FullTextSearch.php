<?php

namespace OC\FullTextSearch\Model {

    class DocumentAccess {
        public function __construct(string $ownerId = '') {}
        public function setUsers(array $users): void {}
        public function setGroups(array $groups): void {}
        public function getOwnerId(): string { return ''; }
        public function getViewerId(): string { return ''; }
        public function getUsers(): array { return []; }
        public function getGroups(): array { return []; }
    }

    class IndexDocument {
        public function __construct(string $providerId, string $documentId) {}
        public function initHash(): void {}
        public function getId(): string { return ''; }
        public function getProviderId(): string { return ''; }
        public function getTitle(): string { return ''; }
        public function setTitle(string $title): self { return $this; }
        public function getContent(): string { return ''; }
        public function setContent(string $content): self { return $this; }
        public function setHash(string $hash): self { return $this; }
        public function getHash(): string { return ''; }
        public function setSource(string $source): self { return $this; }
        public function getSource(): string { return ''; }
        public function setAccess(DocumentAccess $access): self { return $this; }
        public function getAccess(): DocumentAccess { return new DocumentAccess(); }
        public function setScore(string $score): self { return $this; }
        public function setExcerpts(array $excerpts): self { return $this; }
        public function setLink(string $link): self { return $this; }
        public function getLink(): string { return ''; }
        public function getIndex(): Index { return new Index(); }
        public function isContentEncoded(): int { return 0; }
    }

    class Index {
        public function addError(string $message, string $exception, int $sev): void {}
    }
}