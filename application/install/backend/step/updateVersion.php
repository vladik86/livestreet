<?php

class InstallStepUpdateVersion extends InstallStep {

	protected $aVersionConvert=array(
		'1.0.3'
	);

	public function init() {
		/**
		 * Полчаем данные коннекта к БД из конфига
		 */
		InstallConfig::$sFileConfig=dirname(INSTALL_DIR).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'config.local.php';
	}

	public function show() {
		$this->assign('from_version',InstallCore::getStoredData('update_from_version'));
		$this->assign('convert_versions',$this->aVersionConvert);
	}

	public function process() {
		/**
		 * Коннект к серверу БД
		 */
		if (!$oDb=$this->getDBConnection(InstallConfig::get('db.params.host'),InstallConfig::get('db.params.port'),InstallConfig::get('db.params.user'),InstallConfig::get('db.params.pass'))) {
			return false;
		}
		/**
		 * Выбираем БД
		 */
		if (!@mysqli_select_db($oDb,InstallConfig::get('db.params.dbname'))) {
			return $this->addError(InstallCore::getLang('db.errors.db_query'));
		}

		$sVersion=InstallCore::getRequestStr('from_version');
		/**
		 * Проверяем наличие конвертора
		 * Конвертор представляет собой отдельный метод вида converFrom_X1_Y1_Z1_to_X2_Y2_Z2
		 */
		$sMethod='convertFrom_'.str_replace('.','_',$sVersion).'_to_'.str_replace('.','_',VERSION);
		if (!method_exists($this,$sMethod)) {
			return $this->addError(InstallCore::getLang('steps.updateVersion.errors.not_found_convert'));
		}
		InstallCore::setStoredData('update_from_version',$sVersion);
		/**
		 * Запускаем конвертор
		 */
		return call_user_func_array(array($this,$sMethod),array($oDb));
	}


	/**
	 * Конвертор версии 1.0.3 в 2.0.0
	 *
	 * @param $oDb
	 *
	 * @return bool
	 */
	public function convertFrom_1_0_3_to_2_0_0($oDb) {
		/**
		 * Запускаем SQL патч
		 */
		$sFile='sql'.DIRECTORY_SEPARATOR.'patch_1.0.3_to_2.0.0.sql';
		list($bResult,$aErrors)=array_values($this->importDumpDB($oDb,InstallCore::getDataFilePath($sFile),array('engine'=>InstallConfig::get('db.tables.engine'),'prefix'=>InstallConfig::get('db.table.prefix'),'check_table'=>'cron_task')));
		if ($bResult) {
			/**
			 * Здесь нужно выполнить конвертацию данных
			 */

			return true;
		}
		return $this->addError(join('<br/>',$aErrors));
	}
}