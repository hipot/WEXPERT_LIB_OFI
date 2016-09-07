<?
/**
 * Form maker framework
 * @version 2.0
 * @author weXpert, 2012
 */

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();
/* @var $this CBitrixComponent */

/** TOFUTURE спам-фильтры */
/*if ($arParams['POST_NAME'] == 'recall') {
	if (preg_match('#(<a\s*href\s*=)|([url\s*=)|(link\s*=)#', $arParams['_POST'][ $arParams['POST_NAME'] ]['phone'])) {
		unset($arParams['_POST'][ $arParams['POST_NAME'] ]);
	}
}*/

// вешаем стандартные js-обработчики по-умолчанию
// если нужны не стандатные, то отрубаем и пишем в шаблоне компонента
$arParams['SET_MAIN_JS_CHECKERS'] = ($arParams['SET_MAIN_JS_CHECKERS'] == 'N') ? false : true;

/**
 * Подрубаем языковой файл шаблона компонента, чтобы в нем определять текстовки ошибок (т.е.
 * сообщения вида "RTN_" . $fld и "RTEN_" . $fld) для каждой формы отдельно (шаблон - это одна форма),
 * а не один раз для всего компонента
 */
global $MESS;
include_once ($_SERVER['DOCUMENT_ROOT'] . $this->GetPath() . '/templates/' . $this->GetTemplateName() . '/lang/ru/template.php');


$arResult = array();


// дополнительные обработчики выборок
// если для какой-либо формы нужно что-то довыбрать
@include_once ($_SERVER['DOCUMENT_ROOT'] . $this->GetPath() . '/custom_select.php');
$functionName = 'CustomRequestSelects_' . $arParams["POST_NAME"];
if (function_exists($functionName)) {
	$arResult['CUSTOM_SELECTS'] = $functionName();
}


if (! empty($arParams['_POST'][ $arParams["POST_NAME"] ]) && check_bitrix_sessid())
{
	// проверка обязательных полей
	if(!empty($arParams["REQ_FIELDS"])){
		foreach ($arParams["REQ_FIELDS"] as $k => $f)
		{
			if(is_array($f) && !empty($f))
			{
				foreach($f as $fld)
				{
					if (trim($arParams['_POST'][ $arParams["POST_NAME"] ][$k][$fld]) == "")
						$arResult["error"][] = GetMessage("RTN_".$fld);
					elseif($fld == 'mail' && !check_email($arParams['_POST'][$arParams["POST_NAME"]][$k][$fld]))
						$arResult["error"][] = GetMessage("RTEN_".$fld);
				}
			}
			/**
			 * FIXME переписал, если $arParams["REQ_FIELDS"] - это массив строк, а не массив массивов
			 */
			else if (trim($arParams['_POST'][ $arParams["POST_NAME"] ][$f]) == "") {
				$arResult["error"][] = GetMessage("RTN_" . $f);
			} elseif ($f == 'mail' && !check_email($arParams['_POST'][ $arParams["POST_NAME"] ][$f])) {
				$arResult["error"][] = GetMessage("RTEN_" . $f);
			}
		}
	}


	// check captcha when post
	if ($arParams['USE_CAPTCHA'] == 'Y') {
		if (! $APPLICATION->CaptchaCheckCode(
				$arParams['_POST'][ $arParams["POST_NAME"] ]["captcha_word"],
				$arParams['_POST'][ $arParams["POST_NAME"] ]["captcha_sid"])
			) {
				$arResult["error"][] = GetMessage('REGISTER_WRONG_CAPTCHA');
		}
	}

	$MAIL_VARS = array();
	if(empty($arResult["error"]))
	{

		foreach($arParams['_POST'][$arParams["POST_NAME"]] as $key=>$p)
		{
			if($key == 'interest'){
				foreach ($arParams['_POST'][$arParams["POST_NAME"]][$key] as $val) {
					$pr = $val.'<br />';
					$p .= $pr;
				}
				$MAIL_VARS[$key] = str_replace('Array', '', $p);
				continue;
			}
			$MAIL_VARS[$key] = $p;
		}
		
				
		// добавление элемента, если надо
		if(is_array($arParams['ADD_ELEMENT']) && !empty($arParams['ADD_ELEMENT']['FIELDS'])){
			CModule::IncludeModule("iblock");
			$el = new CIBlockElement();

			foreach ($arParams['ADD_ELEMENT']['FIELDS'] as $k=>$v){
				$arAddFields[$k] = $v;
			}
			if(sizeof($arParams['ADD_ELEMENT']['PROPS'])>0){
				foreach ($arParams['ADD_ELEMENT']['PROPS'] as $pk=>$pv){
					$arProps[$pk] = $pv;
				}
				if(sizeof($arProps)>0) $arAddFields['PROPERTY_VALUES'] = $arProps;
			}

			if($PRODUCT_ID = $el->Add($arAddFields)) {
				$arResult['ADDED_ID']  = $PRODUCT_ID;
				$MAIL_VARS['ADDED_ID'] = $PRODUCT_ID;
			}
			else
				$arResult['error']['add'] = $el->LAST_ERROR;
		}

		/**
		 * редирект делаем на успешный урл если нет ошибок и отправляем письмо
		 */
		if (empty($arResult['error'])) {

			if (trim($arParams['TEMPLATE']) != '') {
				// ар резулт теперь для вывода
				$arResult['MAIL_VARS'] = $MAIL_VARS;
				//$MAIL_VARS = array();
				
				$orig_template = $this->__templateName;
				$this->__templateName = $arParams['TEMPLATE'];
				ob_start();
				$this->IncludeComponentTemplate();
				$MAIL_VARS['HTML'] = ob_get_contents();
				ob_end_clean();
				$this->__templateName = $orig_template;
			}
			
			// дополнительные обработчики после выполнения
			@include_once ($_SERVER['DOCUMENT_ROOT'] . $this->GetPath() . '/custom_mailvars.php');
			$functionName = 'CustomRequestMailVars_' . $arParams["POST_NAME"];
			if (function_exists($functionName)) {
				$functionName($arParams['_POST'], $arResult, $MAIL_VARS);
			}

			
			/*echo '<pre>';
			print_r($MAIL_VARS);
			echo '</pre>';*/
			
			
			//
			// YEEPPP! Send emails here!
			//
			CEvent::Send(
				$arParams["EVENT_TYPE"],
				SITE_ID,
				$MAIL_VARS,
				($arParams["DUBLICATE_MAIL"] == "Y") ? "Y" : "N",
				($arParams["EVENT_ID"] > 0) ? $arParams["EVENT_ID"] : ""
			);

			if (isset($arParams['REDIRECT_URL'])) {
				$redirect = $arParams['REDIRECT_URL'];
			} else {
				//$redirect = $APPLICATION->GetCurPage()."?READY".$arParams["POST_NAME"]."=Y";
				$redirect = $APPLICATION->GetCurPage();
			}
			
			// дополнительные обработчики после выполнения
			@include_once ($_SERVER['DOCUMENT_ROOT'] . $this->GetPath() . '/custom_handlers.php');
			$functionName = 'CustomRequestHandler_' . $arParams["POST_NAME"];
			if (function_exists($functionName)) {
				$functionName($arParams['_POST'], $MAIL_VARS);
			}
			
			// вот так показываем успешное выполнение, чтобы геты не слать в урле (без "?READY".$arParams["POST_NAME"]."=Y")
			$_SESSION[ "READY_request_" . $arParams["POST_NAME"] ] = 'Y';
			if ($arParams['NO_REDIRECT'] != 'Y') {
				LocalRedirect($redirect);
			}
		}
	}
}

// initialize captcha
if ($arParams["USE_CAPTCHA"] == "Y") {
	$arResult["CAPTCHA_CODE"] = htmlspecialchars($APPLICATION->CaptchaGetCode());
}


if ($arParams['NO_TEMPLATE'] != 'Y') {
	
	// основные js-checkers, дополнительные уже подрубаем в шаблоне
	if ($arParams['SET_MAIN_JS_CHECKERS']) {
		$APPLICATION->AddHeadScript($this->GetPath() . '/js/main_checker_script.js');
	}
	$APPLICATION->SetAdditionalCSS($this->GetPath() . '/css/main.css');
	
	$this->IncludeComponentTemplate();
}
?>