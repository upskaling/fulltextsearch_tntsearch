<?php

declare(strict_types=1);

namespace OCA\Fulltextsearch_Tntsearch\Service;

use OCP\IConfig;

class ConfigService {

	public function __construct(
		private IConfig $config,
	) {
	}

	public function getAppValue(string $key, string $default = ''): string {
		return $this->config->getAppValue('fulltextsearch_tntsearch', $key, $default);
	}

	public function setAppValue(string $key, string $value): void {
		$this->config->setAppValue('fulltextsearch_tntsearch', $key, $value);
	}

	public function getAppValueInt(string $key, int $default = 0): int {
		$value = $this->config->getAppValue('fulltextsearch_tntsearch', $key, (string)$default);
		return (int)$value;
	}

	public function getAppValueBool(string $key, bool $default = false): bool {
		$value = $this->config->getAppValue('fulltextsearch_tntsearch', $key, $default ? 'yes' : 'no');
		return in_array($value, ['yes', 'true', '1', 'on'], true);
	}

	public function getConfig(): array {
		return [
			'index_path' => $this->getAppValue('index_path', ''),
			'fuzziness' => $this->getAppValueBool('fuzziness', false),
			'fuzziness_prefix' => $this->getAppValueInt('fuzziness_prefix', 2),
			'stemmer' => $this->getAppValue('stemmer', ''),
			'search_exact' => $this->getAppValueBool('search_exact', false),
			'log_enabled' => $this->getAppValueBool('log_enabled', false),
		];
	}
}
