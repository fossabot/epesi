<?php
/**
 * @author Georgi Hristov <ghristov@gmx.de>
 * @copyright Copyright &copy; 2014, Xoff Software GmbH
 * @license MIT
 * @version 1.0
 * @package epesi-notify
 */
defined("_VALID_ACCESS") || die('Direct access forbidden');

class Base_NotifyInstall extends ModuleInstall {
	const version = '1.0';

	public function install() {
		Base_ThemeCommon::install_default_theme($this->get_type());

		$box_ini_file = Base_ThemeCommon::get_template_file('Base/Box', 'default.ini');
		$ini_setting = PHP_EOL.'[notify]'.PHP_EOL.'module = "Base/Notify"'.PHP_EOL.'display = "logged"';
		file_put_contents($box_ini_file, $ini_setting, FILE_APPEND);

		Utils_CommonDataCommon::new_array('Base_TrayNotify/Timeout', array(-1=>_M('Disable Notification'), 0=>_M('Manually'), 10000=>_M('10 seconds'), 30000=>_M('30 seconds'), 60000=>_M('1 minute')), true, true);
		return true;
	}

	public function uninstall() {
		Base_ThemeCommon::uninstall_default_theme($this->get_type());
		return true;
	}

	public function version() {
		return array(self::version);
	}

	public function requires($v) {
		return array(
		array('name'=>'Base/Acl','version'=>0),
		array('name'=>'Base/User','version'=>0),
		array('name'=>'Base/Theme','version'=>0),
		array('name'=>'Libs/QuickForm','version'=>0));
	}

	public static function info() {
		$html="Pops up tray notification in the OS";
		return array(
		'Description'=>$html,
		'Author'=>'<a href="mailto:ghristov@gmx.de">Georgi Hristov</a>',
		'License'=>'MIT');
	}

	public static function simple_setup() {
		return array('package'=>__('EPESI Core'), 'option'=>__('Web Notifications'), 'version'=>self::version);
	}

}

?>