<?php

declare(strict_types=1);


namespace OCA\Fulltextsearch_Tntsearch\Settings;

use Exception;
use OCA\Fulltextsearch_Tntsearch\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class Admin implements ISettings {

	public function __construct() {
	}

	/**
	 * @return TemplateResponse
	 * @throws Exception
	 */
	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'settings.admin', []);
	}

	/**
	 * @return string the section ID, e.g. 'sharing'
	 */
	public function getSection(): string {
		return 'fulltextsearch';
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 *             the admin section. The forms are arranged in ascending order of the
	 *             priority values. It is required to return a value between 0 and 100.
	 *
	 * keep the server setting at the top, right after "server settings"
	 */
	public function getPriority(): int {
		return 31;
	}
}
