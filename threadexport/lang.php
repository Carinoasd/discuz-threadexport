<?php

/**
 * threadexport — 文案集中處（繁體中文）。
 * 經 threadexport::L($key, $vars) 取用；$vars 以 {name} 佔位、strtr 置換。
 *
 * 作者：Carinoasd
 * (C) 2026 Carinoasd
 */

if(!defined('IN_DISCUZ')) {
	exit('Access Denied');
}

return [

	// ---- 標題／通用 ----
	'plugin_name'   => '主題列表導出',
	'page_title'    => '主題列表導出',
	'footer'        => 'threadexport by Carinoasd · (C) 2026 Carinoasd',

	// ---- 欄位標籤 ----
	'lb_fid'        => '版塊 fid',
	'lb_template'   => '樣板',
	'lb_quick'      => '快捷樣板',
	'lb_filter'     => '篩選',
	'lb_recycle'    => '包含回收站的主題',
	'lb_datefrom'   => '發帖時間　從',
	'lb_dateto'     => '到',
	'lb_dateph'     => 'YYYY-MM-DD',
	'lb_output'     => '輸出格式',
	'lb_bom'        => '檔案開頭加 UTF-8 BOM',
	'lb_eol'        => '換行符',
	'lb_crlf'       => 'Windows（CRLF）',
	'lb_lf'         => 'Linux/Mac（LF）',
	'lb_blank'      => '每筆之間空一行',
	'btn_preview'   => '預覽',
	'btn_download'  => '下載 TXT',
	'q_title'       => '只要標題',
	'q_tidtitle'    => 'tid + 標題',
	'q_url'         => 'URL 標籤',

	// ---- 提示 ----
	'hint_dateblank'=> '（留空＝不限）',
	'hint_bom'      => 'Windows 記事本、Excel 開起來不會亂碼；貼進帖子不受影響。',
	'hint_blank'    => '多行樣板時好讀。',
	'hint_forum_ok' => '版塊：{name}',
	'hint_forum_no' => '查無此版塊',
	'hint_tpl_help' => '佔位符只認「大括號 + 單一大寫字母」；未定義者原樣保留。',

	// ---- 預覽 ----
	'pv_count'      => '符合條件共 {n} 筆。',
	'pv_first20'    => '前 20 筆套用結果：',
	'pv_zero'       => '符合條件共 0 筆；仍可下載（只會得到空檔／只有 BOM）。',
	'pv_empty_tpl'  => '樣板為空，請先填入樣板或按快捷樣板。',

	// ---- 佔位符說明表 ----
	'ph_head_ph'    => '佔位符',
	'ph_head_desc'  => '內容',
	'ph_A' => '主題標題',
	'ph_B' => '主題 tid',
	'ph_C' => '作者用戶名',
	'ph_D' => '作者 uid',
	'ph_E' => '發帖時間（YYYY-MM-DD HH:MM）',
	'ph_F' => '回覆數',
	'ph_G' => '瀏覽數',
	'ph_H' => '主題分類名（無分類則空）',
	'ph_I' => '主題完整網址',
	'ph_J' => '最後回覆時間',
	'ph_K' => '版塊名稱',
	'ph_N' => '流水號（從 1 起）',

	// ---- 錯誤／攔阻 ----
	'err_no_perm'   => '您沒有使用「主題列表導出」的權限（需為創始人，或由站長把您的 uid 加入插件設定的允許清單）。',
	'err_formhash'  => '請求已過期或來源不正確（formhash 檢查未通過），請重新整理頁面再試。',
	'err_bad_fid'   => '查無此版塊，或該 fid 是「分區（group）」而非可導出的版塊。',
	'err_tpl_empty' => '樣板不可為空。',
	'err_tpl_long'  => '樣板過長（上限 {max} 字元）。',
	'err_date_from' => '「從」的日期格式不正確，需為 YYYY-MM-DD。',
	'err_date_to'   => '「到」的日期格式不正確，需為 YYYY-MM-DD。',
	'err_date_order'=> '「從」的日期不可晚於「到」的日期。',

	// ---- 設定頁（本工具頁內的設定摘要） ----
	'cfg_title'     => '目前設定',
	'cfg_allow'     => '額外允許的 uid',
	'cfg_batch'     => '每批撈取筆數',
	'cfg_bom'       => 'BOM 預設',
	'cfg_crlf'      => '換行符預設',
	'cfg_edit_hint' => '要修改設定，請至「應用 → 插件 → 主題列表導出 → 設定」（Discuz 標準插件設定頁）。',
	'cfg_yes'       => '是',
	'cfg_no'        => '否',
	'cfg_win'       => 'Windows（CRLF）',
	'cfg_nix'       => 'Linux/Mac（LF）',
	'cfg_empty'     => '（空，只有創始人能用）',
];
