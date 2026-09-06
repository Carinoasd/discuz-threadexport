<?php

/**
 * threadexport — 安裝。
 *
 * 本插件是只讀工具，不建任何資料表、不寫任何論壇資料。
 * 安裝只需讓 Discuz 建立 pre_common_plugin / pre_common_pluginvar 兩列（由匯入器處理），
 * 這裡不做任何 DDL/DML。
 *
 * 作者：Carinoasd
 * (C) 2026 Carinoasd
 */

if(!defined('IN_DISCUZ')) {
	exit('Access Denied');
}

$finish = TRUE;
