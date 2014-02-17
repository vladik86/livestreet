<?php
/**
 * LiveStreet CMS
 * Copyright © 2013 OOO "ЛС-СОФТ"
 *
 * ------------------------------------------------------
 *
 * Official site: www.livestreetcms.com
 * Contact e-mail: office@livestreetcms.com
 *
 * GNU General Public License, version 2:
 * http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 * ------------------------------------------------------
 *
 * @link http://www.livestreetcms.com
 * @copyright 2013 OOO "ЛС-СОФТ"
 * @author Maxim Mzhelskiy <rus.engine@gmail.com>
 *
 */

/**
 * Модуль управления медиа-данными (изображения, видео и т.п.)
 */
class ModuleMedia extends ModuleORM {
	/**
	 * Список типов медиа
	 * Свои кастомные типы необходимо нумеровать с 1000
	 */
	const TYPE_IMAGE=1;
	const TYPE_VIDEO=2;

	/**
	 * Объект текущего пользователя
	 *
	 * @var ModuleUser_EntityUser|null
	 */
	protected $oUserCurrent;

	protected $oMapper=null;

	protected $aTargetTypes=array(
		'topic'=>array(),
		'comment'=>array(),
	);

	/**
	 * Список доступных типов медиа
	 *
	 * @var array
	 */
	protected $aMediaTypes=array(
		self::TYPE_IMAGE,self::TYPE_VIDEO
	);

	/**
	 * Инициализация
	 *
	 */
	public function Init() {
		parent::Init();
		$this->oMapper=Engine::GetMapper(__CLASS__);
		$this->oUserCurrent=$this->User_GetUserCurrent();
	}
	/**
	 * Возвращает список типов объектов
	 *
	 * @return array
	 */
	public function GetTargetTypes() {
		return $this->aTargetTypes;
	}
	/**
	 * Добавляет в разрешенные новый тип
	 *
	 * @param string $sTargetType	Тип
	 * @param array $aParams	Параметры
	 * @return bool
	 */
	public function AddTargetType($sTargetType,$aParams=array()) {
		if (!array_key_exists($sTargetType,$this->aTargetTypes)) {
			$this->aTargetTypes[$sTargetType]=$aParams;
			return true;
		}
		return false;
	}
	/**
	 * Проверяет разрешен ли данный тип
	 *
	 * @param string $sTargetType	Тип
	 * @return bool
	 */
	public function IsAllowTargetType($sTargetType) {
		return in_array($sTargetType,array_keys($this->aTargetTypes));
	}
	/**
	 * Возвращает парметры нужного типа
	 *
	 * @param string $sTargetType
	 *
	 * @return mixed
	 */
	public function GetTargetTypeParams($sTargetType) {
		if ($this->IsAllowTargetType($sTargetType)) {
			return $this->aTargetTypes[$sTargetType];
		}
	}
	/**
	 * Проверяет разрешен ли тип медиа
	 *
	 * @param string $sType
	 *
	 * @return bool
	 */
	public function IsAllowMediaType($sType) {
		return in_array($sType,$this->aMediaTypes);
	}
	/**
	 * Проверка объекта target - владелец медиа
	 *
	 * @param string $sTargetType	Тип
	 * @param int $iTargetId	 ID владельца
	 * @return bool
	 */
	public function CheckTarget($sTargetType,$iTargetId) {
		if (!$this->IsAllowTargetType($sTargetType)) {
			return false;
		}
		$sMethod = 'CheckTarget'.func_camelize($sTargetType);
		if (method_exists($this,$sMethod)) {
			return $this->$sMethod($iTargetId);
		}
		return false;
	}

	public function Upload($aFile,$sTargetType,$sTargetId,$sTargetTmp=null) {
		if (is_string($aFile)) {
			return $this->UploadUrl($aFile,$sTargetType,$sTargetId,$sTargetTmp);
		} else {
			return $this->UploadLocal($aFile,$sTargetType,$sTargetId,$sTargetTmp);
		}
	}

	public function UploadLocal($aFile,$sTargetType,$sTargetId,$sTargetTmp=null) {
		if(!is_array($aFile) || !isset($aFile['tmp_name']) || !isset($aFile['name'])) {
			return false;
		}

		$aPathInfo=pathinfo($aFile['name']);
		$sExtension=isset($aPathInfo['extension']) ? $aPathInfo['extension'] : 'unknown';
		$sFileName = $aPathInfo['filename'].'.'.$sExtension;
		/**
		 * Копируем загруженный файл
		 */
		$sDirTmp=Config::Get('path.tmp.server').'/media/';
		if (!is_dir($sDirTmp)) {
			@mkdir($sDirTmp,0777,true);
		}
		$sFileTmp=$sDirTmp.$sFileName;
		if (!move_uploaded_file($aFile['tmp_name'],$sFileTmp)) {
			return 'Не удалось загрузить файл';
		}
		/**
		 * TODO: проверить на размер файла в байтах
		 */

		return $this->ProcessingFile($sFileTmp,$sTargetType,$sTargetId,$sTargetTmp);
	}

	public function UploadUrl($sFileUrl,$sTargetType,$sTargetId,$sTargetTmp=null) {
		/**
		 * Проверяем, является ли файл изображением
		 * TODO: файл может быть не только изображением, поэтому требуется рефакторинг
		 */
		if(!$aImageInfo=(@getimagesize($sFileUrl))) {
			return 'Файл не является изображением';
		}
		$aTypeImage=array(1=>'gif',2=>'jpg',3=>'png'); // see http://php.net/manual/en/function.exif-imagetype.php
		$sExtension=isset($aTypeImage[$aImageInfo[2]]) ? $aTypeImage[$aImageInfo[2]] : 'jpg';
		/**
		 * Открываем файловый поток и считываем файл поблочно,
		 * контролируя максимальный размер изображения
		 */
		$rFile=fopen($sFileUrl,'r');
		if(!$rFile) {
			return 'Не удалось загрузить файл';
		}

		$iMaxSizeKb=Config::Get('module.media.image.max_size_url');
		$iSizeKb=0;
		$sContent='';
		while (!feof($rFile) and $iSizeKb<$iMaxSizeKb) {
			$sContent.=fread($rFile ,1024*2);
			$iSizeKb++;
		}
		/**
		 * Если конец файла не достигнут,
		 * значит файл имеет недопустимый размер
		 */
		if(!feof($rFile)) {
			return 'Превышен максимальный размер файла: '.Config::Get('module.media.image.max_size_url').'Kb';
		}
		fclose($rFile);
		/**
		 * Копируем загруженный файл
		 */
		$sDirTmp=Config::Get('path.tmp.server').'/media/';
		if (!is_dir($sDirTmp)) {
			@mkdir($sDirTmp,0777,true);
		}
		$sFileTmp=$sDirTmp.func_generator().'.'.$sExtension;
		$rFile=fopen($sFileTmp,'w');
		fwrite($rFile,$sContent);
		fclose($rFile);

		return $this->ProcessingFile($sFileTmp,$sTargetType,$sTargetId,$sTargetTmp);
	}

	public function ProcessingFile($sFileTmp,$sTargetType,$sTargetId,$sTargetTmp=null) {
		/**
		 * Определяем тип файла по расширенияю и запускаем обработку
		 */
		$aPathInfo=pathinfo($sFileTmp);
		$sExtension=@strtolower($aPathInfo['extension']);
		if (in_array($sExtension,array('jpg','jpeg','gif','png'))) {
			return $this->ProcessingFileImage($sFileTmp,$sTargetType,$sTargetId,$sTargetTmp);
		}
		return 'Неверный тип файла';
	}

	public function ProcessingFileImage($sFileTmp,$sTargetType,$sTargetId,$sTargetTmp=null) {
		$aPathInfo=pathinfo($sFileTmp);
		$aParams=$this->Image_BuildParams('media.'.$sTargetType);
		/**
		 * Если объект изображения не создан, возвращаем ошибку
		 */
		if(!$oImage=$this->Image_Open($sFileTmp,$aParams)) {
			$this->Fs_RemoveFileLocal($sFileTmp);
			return $this->Image_GetLastError();
		}
		$iWidth=$oImage->getWidth();
		$iHeight=$oImage->getHeight();

		$sPath=$this->GetSaveDir($sTargetType,$sTargetId);
		/**
		 * Уникальное имя файла
		 */
		$sFileName=func_generator(20);
		/**
		 * Сохраняем оригинальную копию
		 */
		if (!$sFileResult=$oImage->saveSmart($sPath,$sFileName)) {
			$this->Fs_RemoveFileLocal($sFileTmp);
			return $this->Image_GetLastError();
		}

		$aSizes=Config::Get("module.media.type.{$sTargetType}.image.sizes");
		if (!$aSizes) {
			$aSizes=Config::Get("module.media.image.sizes");
		}
		/**
		 * Генерируем варианты с необходимыми размерами
		 */
		$this->GenerateImageBySizes($sFileTmp,$sPath,$sFileName,$aSizes,$aParams);
		/**
		 * Сохраняем медиа
		 */
		$oMedia=Engine::GetEntity('ModuleMedia_EntityMedia');
		$oMedia->setUserId($this->oUserCurrent ? $this->oUserCurrent->getId() : null);
		$oMedia->setType(self::TYPE_IMAGE);
		$oMedia->setTargetType($sTargetType);
		$oMedia->setFilePath($sFileResult);
		$oMedia->setFileName($aPathInfo['filename']);
		$oMedia->setFileSize(filesize($sFileTmp));
		$oMedia->setWidth($iWidth);
		$oMedia->setHeight($iHeight);
		$oMedia->setDataOne('image_sizes',$aSizes);
		/**
		 * Теперь можно удалить временный файл
		 */
		$this->Fs_RemoveFileLocal($sFileTmp);
		/**
		 * Добавляем в БД
		 */
		if ($oMedia->Add()) {
			/**
			 * Создаем связь с владельцем
			 */
			$oTarget=Engine::GetEntity('ModuleMedia_EntityTarget');
			$oTarget->setMediaId($oMedia->getId());
			$oTarget->setTargetType($sTargetType);
			$oTarget->setTargetId($sTargetId ? $sTargetId : null);
			$oTarget->setTargetTmp($sTargetTmp ? $sTargetTmp : null);
			if ($oTarget->Add()) {
				return $oMedia;
			}
		}
		return false;
	}
	/**
	 * Создает набор отресайзанных изображений
	 * Варианты наименований результирующих файлов в зависимости от размеров:
	 * 	file_100x150 - w=100 h=150 crop=false
	 * 	file_100x150crop - w=100 h=150 crop=true
	 * 	file_x150 - w=null h=150 crop=false
	 * 	file_100x - w=100 h=null crop=false
	 *
	 * @param      $sFileSource
	 * @param      $sDirDist
	 * @param      $sFileName
	 * @param      $aSizes
	 * @param null $aParams
	 */
	public function GenerateImageBySizes($sFileSource,$sDirDist,$sFileName,$aSizes,$aParams=null) {
		if (!$aSizes) {
			return;
		}
		/**
		 * Преобразуем упрощенную запись списка размеров в полную
		 */
		foreach($aSizes as $k=>$v) {
			if (is_numeric($v)) {
				$aSizes[$k]=array(
					'w' => $v,
					'h' => $v,
					'crop' => true,
				);
			}
		}

		foreach ($aSizes as $aSize) {
			/**
			 * Для каждого указанного в конфиге размера генерируем картинку
			 */
			$sNewFileName = $sFileName.'_'.$aSize['w'].'x'.$aSize['h'];
			if($oImage=$this->Image_Open($sFileSource,$aParams)) {
				if ($aSize['crop']) {
					$oImage->cropProportion($aSize['w']/$aSize['h'],'center');
					$sNewFileName .= 'crop';
				}
				if (!$oImage->resize($aSize['w'],$aSize['h'],true)->saveSmart($sDirDist,$sNewFileName)) {
					// TODO: прерывать и возвращать false?
				}
			}
		}
	}
	public function RemoveImageBySizes($sPath,$aSizes,$bRemoveOriginal=true) {
		if ($aSizes) {
			/**
			 * Преобразуем упрощенную запись списка размеров в полную
			 */
			foreach($aSizes as $k=>$v) {
				if (is_numeric($v)) {
					$aSizes[$k]=array(
						'w' => $v,
						'h' => $v,
						'crop' => true,
					);
				}
			}
			foreach($aSizes as $aSize) {
				$sSize = $aSize['w'].'x'.$aSize['h'];
				if ($aSize['crop']) {
					$sSize.='crop';
				}
				$this->Image_RemoveFile($this->GetImagePathBySize($sPath,$sSize));
			}
		}
		/**
		 * Удаляем оригинал
		 */
		if ($bRemoveOriginal) {
			$this->Image_RemoveFile($sPath);
		}
	}
	/**
	 * Возвращает каталог для сохранения контента медиа
	 *
	 * @param string  $sTargetType
	 * @param string|null $sTargetId	Желательно для одного типа при формировании каталога для загрузки выбрать что-то одно - использовать $sTargetId или нет
	 *
	 * @return string
	 */
	public function GetSaveDir($sTargetType,$sTargetId=null) {
		return Config::Get('path.uploads.base')."/media/{$sTargetType}/".date('Y/m/d/H/');
	}

	public function BuildCodeForEditor($oMedia,$aParams) {
		$sCode='';
		if ($oMedia->getType()==self::TYPE_IMAGE) {
			$aSizes=(array)$oMedia->getDataOne('image_sizes');

			$sSizeParam=isset($aParams['size']) ? (string)$aParams['size'] : '';
			$sSize='original';
			$bNeedHref=false;
			/**
			 * Проверяем корректность размера
			 */
			foreach($aSizes as $aSizeAllow) {
				$sSizeKey=$aSizeAllow['w'].'x'.$aSizeAllow['h'].($aSizeAllow['crop'] ? 'crop' : '');
				if ($sSizeKey==$sSizeParam) {
					$sSize=$sSizeKey;
					/**
					 * Необходимость лайтбокса
					 */
					if ($aSizeAllow['w']<$oMedia->getWidth()) {
						$bNeedHref=true;
					}
				}
			}


			$sPath=$oMedia->getFileWebPath($sSize=='original' ? null : $sSize);

			$sCode='<img src="'.$sPath.'" ';
			if (!isset($aParams['title'])) {
				$aParams['title']=$oMedia->getDataOne('title');
			}
			if (!isset($aParams['skip_title']) and $aParams['title']) {
				$sCode.=' title="'.htmlspecialchars($aParams['title']).'" ';
				$sCode.=' alt="'.htmlspecialchars($aParams['title']).'" ';
			}
			if (isset($aParams['align']) and in_array($aParams['align'],array('left','right','center'))) {
				if ($aParams['align'] == 'center') {
					$sCode.=' class="image-center"';
				} else {
					$sCode.=' align="'.htmlspecialchars($aParams['align']).'" ';
				}
			}
			$sDataParams='';
			if (isset($aParams['data']) and is_array($aParams['data'])) {
				foreach($aParams['data'] as $sDataName=>$sDataValue) {
					if ($sDataValue) {
						$sDataParams.=' data-'.$sDataName.'="'.htmlspecialchars($sDataValue).'"';
					}
				}
			}
			if ($bNeedHref) {
				$sCode.=' />';
				$sLbxGroup='';
				if (isset($aParams['lbx_group'])) {
					$sLbxGroup=' data-rel="'.htmlspecialchars($aParams['lbx_group']).'"';
				}
				$sCode='<a class="js-lbx" '.$sLbxGroup.' href="'.$oMedia->getFileWebPath().'" '.$sDataParams.'>'.$sCode.'</a>';
			} else {
				$sCode.=$sDataParams.' />';
			}
		}

		return $sCode;
	}

	public function GetMediaByTarget($sTargetType,$iTargetId,$iUserId=null) {
		return $this->oMapper->GetMediaByTarget($sTargetType,$iTargetId,$iUserId);
	}

	public function GetMediaByTargetTmp($sTargetTmp,$iUserId=null) {
		return $this->oMapper->GetMediaByTargetTmp($sTargetTmp,$iUserId);
	}
	/**
	 * Выполняет удаление файлов медиа-объекта
	 *
	 * @param $oMedia
	 */
	public function DeleteFiles($oMedia) {
		/**
		 * Сначала удаляем все файлы
		 */
		if ($oMedia->getType()==self::TYPE_IMAGE) {
			$aSizes=$oMedia->getDataOne('image_sizes');
			$this->RemoveImageBySizes($oMedia->getFilePath(),$aSizes);
		}
	}
	/**
	 * Возвращает список media с учетов прав доступа текущего пользователя
	 *
	 * @param array $aId
	 *
	 * @return array
	 */
	public function GetAllowMediaItemsById($aId) {
		$aIdItems=array();
		foreach((array)$aId as $iId) {
			$aIdItems[]=(int)$iId;
		}

		if (is_array($aIdItems) and count($aIdItems)) {
			$iUserId=$this->oUserCurrent ? $this->oUserCurrent->getId() : null;
			return $this->Media_GetMediaItemsByFilter(array(
														  '#where'=>array('id in (?a) AND ( user_id is null OR user_id = ?d )'=>array($aIdItems,$iUserId))
													  )
			);
		}
		return array();
	}
	/**
	 * Обработка тега gallery в тексте
	 * <pre>
	 * <gallery items="12,55,38" />
	 * </pre>
	 *
	 * @param string $sTag	Тег на ктором сработал колбэк
	 * @param array $aParams Список параметров тега
	 * @return string
	 */
	public function CallbackParserTagGallery($sTag,$aParams) {
		if (isset($aParams['items'])) {
			$aItems=explode(',',$aParams['items']);
		}

		if (!(isset($aItems) and $aMediaItems=$this->Media_GetAllowMediaItemsById($aItems))) {
			return '';
		}

		$aParamsMedia=array(
			'size'=>'100crop',
			'skip_title'=>true
		);
		$sProperties='';
		if (isset($aParams['nav']) and in_array($aParams['nav'],array('thumbs'))) {
			$sProperties.=' data-nav="'.$aParams['nav'].'" ';
		}
		$sTextResult='<div class="fotorama" '.$sProperties.'>'."\r\n";
		foreach($aMediaItems as $oMedia) {
			if (isset($aParams['caption']) and $aParams['caption']) {
				$aParamsMedia['data']['caption']=htmlspecialchars($oMedia->getDataOne('title'));
			}
			$sTextResult.="\t".$this->Media_BuildCodeForEditor($oMedia,$aParamsMedia)."\r\n";
		}
		$sTextResult.="</div>\r\n";
		return $sTextResult;
	}
	/**
	 * Заменяет временный идентификатор на необходимый ID объекта
	 *
	 * @param string	$sTargetType
	 * @param string	$sTargetId
	 * @param null|string	$sTargetTmp	Если не задан, то берется их куки "media_target_tmp_{$sTargetType}"
	 */
	public function ReplaceTargetTmpById($sTargetType,$sTargetId,$sTargetTmp=null) {
		$sCookieKey='media_target_tmp_'.$sTargetType;
		if (is_null($sTargetTmp) and isset($_COOKIE[$sCookieKey])) {
			$sTargetTmp=$_COOKIE[$sCookieKey];
			setcookie($sCookieKey,null,-1,Config::Get('sys.cookie.path'),Config::Get('sys.cookie.host'));
		}
		if (is_string($sTargetTmp)) {
			$aTargetItems=$this->Media_GetTargetItemsByTargetTmpAndTargetType($sTargetTmp,$sTargetType);
			foreach($aTargetItems as $oTarget) {
				$oTarget->setTargetTmp(null);
				$oTarget->setTargetId($sTargetId);
				$oTarget->Update();
			}
		}
	}
	/**
	 * Удаляет связь с медиа данными + при необходимости удаляет сами медиа данные
	 *
	 * @param string $sTargetType
	 * @param int $sTargetId
	 * @param bool $bMediaRemove	Удалять медиа данные оставшиеся без связей
	 */
	public function RemoveTarget($sTargetType,$sTargetId,$bMediaRemove=true) {
		/**
		 * Получаем прикрепленные медиа
		 */
		$aMediaItems=$this->GetMediaByTarget($sTargetType,$sTargetId);
		/**
		 * Удаляем все связи текущего таргета
		 */
		$this->RemoveTargetByTypeAndId($sTargetType,$sTargetId);
		if ($bMediaRemove) {
			/**
			 * Проверяем с какими медиа данными еще остались связи
			 */
			$aMediaIds=array();
			foreach($aMediaItems as $oMediaItem) {
				$aMediaIds[]=$oMediaItem->getId();
			}
			$aTargetItems=$this->GetTargetItemsByFilter(array('media_id in'=>$aMediaIds,'#index-group'=>'media_id'));
			/**
			 * Удаляем медиа данные без оставшихся связей
			 */
			foreach($aMediaItems as $oMediaItem) {
				if (!isset($aTargetItems[$oMediaItem->getId()])) {
					$oMediaItem->Delete();
				}
			}
		}
	}
	public function RemoveTargetByTypeAndId($sTargetType,$iTargetId) {
		$bRes=$this->oMapper->RemoveTargetByTypeAndId($sTargetType,$iTargetId);
		/**
		 * Сбрасываем кеши
		 */
		$this->Cache_Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array(
			'ModuleMedia_EntityTarget_delete'
		));
		return $bRes;
	}

	public function GetFileWebPath($oMedia,$sSize=null) {
		if ($oMedia->getType()==self::TYPE_IMAGE) {
			/**
			 * Проверяем необходимость автоматического создания превью нужного размера - если разрешено настройками и файл НЕ существует
			 */
			if (Config::Get('module.media.image.autoresize') and !$this->Image_IsExistsFile($this->GetImagePathBySize($oMedia->getFilePath(),$sSize))) {
				/**
				 * Запускаем генерацию изображения нужного размера
				 */
				$aSize=$this->ParsedImageSize($sSize);

				$aParams=$this->Image_BuildParams('media.'.$oMedia->getTargetType());
				$sNewFileName=$this->GetImagePathBySize($oMedia->getFilePath(),$sSize);
				if($oImage=$this->Image_OpenFrom($oMedia->getFilePath(),$aParams)) {
					if ($aSize['crop']) {
						$oImage->cropProportion($aSize['w']/$aSize['h'],'center');
					}
					$oImage->resize($aSize['w'],$aSize['h'],true)->save($sNewFileName);
					/**
					 * Обновляем список размеров
					 */
					$aSizeOld=(array)$oMedia->getDataOne('image_sizes');
					$aSizeOld[]=$aSize;
					$oMedia->setDataOne('image_sizes',$aSizeOld);
					$oMedia->Update();
				}
			}
			return $this->GetImageWebPath($oMedia->getFilePath(),$sSize);
		}
		return null;
	}

	public function GetImageWebPath($sPath,$sSize=null) {
		$sPath=$this->Fs_GetPathWeb($sPath);
		if ($sSize) {
			return $this->GetImagePathBySize($sPath,$sSize);
		} else {
			return $sPath;
		}
	}
	/**
	 * Возвращает путь до изображения конкретного размера
	 * Варианты преобразования размеров в имя файла:
	 * 	100 - file_100x100
	 * 	100crop - file_100x100crop
	 * 	100x150 - file_100x150
	 *  100x150crop - file_100x150crop
	 *  x150 - file_x150
	 *  100x - file_100x
	 *
	 * @param string $sPath
	 * @param string $sSize
	 *
	 * @return string
	 */
	public function GetImagePathBySize($sPath,$sSize) {
		$aPathInfo=pathinfo($sPath);
		if (preg_match('#^(\d+)([a-z]{2,10})?$#i',$sSize,$aMatch)) {
			$sSize=$aMatch[1].'x'.$aMatch[1];
			if (isset($aMatch[2])) {
				$sSize.=strtolower($aMatch[2]);
			}
		}
		return $aPathInfo['dirname'].'/'.$aPathInfo['filename'].'_'.$sSize.'.'.$aPathInfo['extension'];
	}

	/**
	 * Парсит строку с размером изображения
	 * Варианты входной строки:
	 * 100
	 * 100crop
	 * 100x150
	 * 100x150crop
	 * x150
	 * 100x
	 *
	 * @param string $sSize
	 *
	 * @return array	Массив вида array('w'=>100,'h'=>150,'crop'=>true)
	 */
	public function ParsedImageSize($sSize) {
		$aSize=array(
			'w'=>null,
			'h'=>null,
			'crop'=>false,
		);

		if (preg_match('#^(\d+)?(x)?(\d+)?([a-z]{2,10})?$#Ui',$sSize,$aMatch)) {
			$iW=(isset($aMatch[1]) and $aMatch[1]) ? $aMatch[1] : null;
			$iH=(isset($aMatch[3]) and $aMatch[3]) ? $aMatch[3] : null;
			$bDelim=(isset($aMatch[2]) and $aMatch[2]) ? true : false;
			$sMod=(isset($aMatch[4]) and $aMatch[4]) ? $aMatch[4] : '';

			if (!$bDelim) {
				$iW=$iH;
			}
			$aSize['w']=$iW;
			$aSize['h']=$iH;
			if ($sMod) {
				$aSize[$sMod]=true;
			}
		}
		return $aSize;
	}

	/**
	 * Проверка владельца с типом "topic"
	 * Название метода формируется автоматически
	 *
	 * @param int $iTargetId	ID владельца
	 * @return bool
	 */
	public function CheckTargetTopic($iTargetId) {
		if ($oTopic=$this->Topic_GetTopicById($iTargetId)) {
			/**
			 * Проверяем права на редактирование топика
			 */
			if ($this->ACL_IsAllowEditTopic($oTopic,$this->oUserCurrent)) {
				return true;
			}
		}
		return false;
	}
	/**
	 * Проверка владельца с типом "comment"
	 * Название метода формируется автоматически
	 *
	 * @param int $iTargetId	ID владельца
	 * @return bool
	 */
	public function CheckTargetComment($iTargetId) {
		if ($oComment=$this->Comment_GetCommentById($iTargetId)) {
			/**
			 * Проверяем права на редактирование комментария
			 */
			if ($this->ACL_IsAllowEditComment($oComment,$this->oUserCurrent)) {
				return true;
			}
		}
		return false;
	}
}