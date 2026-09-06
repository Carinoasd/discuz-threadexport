<?php

/**
 * threadexport — 版塊主題列表 TXT 導出插件 · 核心類
 *
 * 只讀的站務小工具：把某一個版塊的每個主題套進站長貼的樣板，一主題一份，
 * 由新到舊疊起來，串成一個 .txt 檔讓站長下載。整個插件只有 SELECT，
 * 不建資料表、不改任何論壇資料、不加前台 hook。
 *
 * 本檔集中：常數、設定讀取、文案、佔位符替換、實體還原、查詢與分批串流。
 * 後台頁（控制流）在 admincp.inc.php；文案在 lang.php。
 *
 * 作者：Carinoasd
 * (C) 2026 Carinoasd
 */

if(!defined('IN_DISCUZ')) {
	exit('Access Denied');
}

if(!defined('THREADEXPORT_ROOT')) {
	define('THREADEXPORT_ROOT', DISCUZ_ROOT.'./source/plugin/threadexport/');
}

class threadexport {

	const IDENTIFIER = 'threadexport';
	const VERSION    = '1.0.0';

	/* 樣板長度上限（§5.2-10） */
	const TPL_MAX = 2000;

	/* ---------- 設定（§9） ---------- */

	public static function defaults() {
		return [
			'allow_uids'   => '',
			'batch_size'   => 2000,
			'default_bom'  => 1,
			'default_crlf' => 1,
		];
	}

	public static function config() {
		global $_G;
		static $cfg = null;
		if($cfg === null) {
			// 後台 context 未必已載入 plugin 快取；不在就補載一次（快取在 DB syscache）。
			if(!isset($_G['cache']['plugin']) || !is_array($_G['cache']['plugin'])) {
				if(!function_exists('loadcache')) {
					require_once libfile('function/cache');
				}
				loadcache('plugin');
			}
			$raw = isset($_G['cache']['plugin']['threadexport']) && is_array($_G['cache']['plugin']['threadexport'])
				? $_G['cache']['plugin']['threadexport'] : [];
			$cfg = array_merge(self::defaults(), $raw);
		}
		return $cfg;
	}

	public static function setting($key, $default = null) {
		$cfg = self::config();
		return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
	}

	/** 每批撈取筆數，夾在 500～5000（§8-1）。 */
	public static function batch_size() {
		$n = (int)self::setting('batch_size', 2000);
		if($n < 500)  { $n = 500; }
		if($n > 5000) { $n = 5000; }
		return $n;
	}

	/** 額外允許的 uid 清單（逗號分隔）→ 去重後的正整數陣列（§3、§9）。 */
	public static function allow_uids() {
		$raw = (string)self::setting('allow_uids', '');
		$out = [];
		foreach(preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $tok) {
			if(ctype_digit($tok)) {
				$out[(int)$tok] = true;
			}
		}
		return array_keys($out);
	}

	/**
	 * §3 兩層權限，滿足其一即可：
	 *   1) 目前登入者是創始人（isfounder()，讀目前 member；X5 的 checkfounder 要 member 陣列）；
	 *   2) uid 在「額外允許的 uid」清單中。
	 * 注意：規格寫 isfounder($_G['uid'])，但 X5.0.2 的 checkfounder() 需要完整 member 陣列
	 * （驗 uid/groupid==1/adminid==1），傳 int 會恆為 false —— 故此處用 isfounder() 無參數版。
	 */
	public static function can_use($uid) {
		if(function_exists('isfounder') && isfounder()) {
			return true;
		}
		return in_array((int)$uid, self::allow_uids(), true);
	}

	/* ---------- 文案（lang.php） ---------- */

	public static function L($key, $vars = []) {
		static $lang = null;
		if($lang === null) {
			$lang = include THREADEXPORT_ROOT.'lang.php';
		}
		$s = isset($lang[$key]) ? $lang[$key] : $key;
		if($vars) {
			$rep = [];
			foreach($vars as $k => $v) {
				$rep['{'.$k.'}'] = $v;
			}
			$s = strtr($s, $rep);
		}
		return $s;
	}

	/* ---------- 實體還原（§5.2-7，推翻規格） ---------- */

	/**
	 * subject 入庫時經過 dhtmlspecialchars()（source/app/forum/module/post.php:205），
	 * 即 str_replace(['&','"','<','>'], ['&amp;','&quot;','&lt;','&gt;'])。
	 * X5.0.2 原始碼裡「沒有」dhtmlspecialchars_decode() 這個函式（全樹查無定義），
	 * 故不能照規格 §5.2-7 呼叫它；改用精確反向：先解 &lt;/&gt;/&quot;，最後才解 &amp;，
	 * 才能正確還原使用者字面輸入的 &lt; 之類（存成 &amp;lt;）。等同 htmlspecialchars_decode(ENT_QUOTES)。
	 */
	public static function decode_entities($s) {
		return str_replace(['&lt;', '&gt;', '&quot;', '&amp;'], ['<', '>', '"', '&'], (string)$s);
	}

	/* ---------- 版塊驗證（§6.3） ---------- */

	/** 直接查 pre_forum_forum（不依賴 forum 快取），回 [fid,name,type] 或 false。 */
	public static function fetch_forum($fid) {
		$fid = (int)$fid;
		if($fid <= 0) {
			return false;
		}
		return DB::fetch_first('SELECT fid, name, type FROM %t WHERE fid=%d', ['forum_forum', $fid]);
	}

	/** fid 存在且 type 為 forum 或 sub（不是 group 分區）才可導。 */
	public static function forum_exportable($forum) {
		return $forum && in_array($forum['type'], ['forum', 'sub'], true);
	}

	/** 版塊的主題分類表：typeid → 還原後的分類名（§5.1 {H}、§8-6 一次讀進陣列）。 */
	public static function threadclass_map($fid) {
		$map = [];
		foreach(DB::fetch_all('SELECT typeid, name FROM %t WHERE fid=%d', ['forum_threadclass', (int)$fid]) as $r) {
			$map[(int)$r['typeid']] = self::decode_entities($r['name']);
		}
		return $map;
	}

	/* ---------- 日期範圍（§6.1、§10-4） ---------- */

	/** 站台時區偏移（小時）。用系統設定，讓導出與操作者個人時區無關、可重現。 */
	public static function site_offset() {
		global $_G;
		return (int)(isset($_G['setting']['timeoffset']) ? $_G['setting']['timeoffset'] : 0);
	}

	/**
	 * 解析 YYYY-MM-DD。回 [y,m,d] 或 false（格式/日期不合法）。
	 */
	public static function parse_date($s) {
		$s = trim((string)$s);
		if($s === '') {
			return null; // 留空
		}
		if(!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
			return false;
		}
		$y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
		if(!checkdate($mo, $d, $y)) {
			return false;
		}
		return [$y, $mo, $d];
	}

	/** 「從」該日 00:00:00（站台時區）→ unix 時間戳。 */
	public static function day_start_ts($ymd) {
		$off = self::site_offset();
		return gmmktime(0, 0, 0, $ymd[1], $ymd[2], $ymd[0]) - $off * 3600;
	}

	/** 「到」該日 23:59:59（站台時區）→ unix 時間戳。 */
	public static function day_end_ts($ymd) {
		$off = self::site_offset();
		return gmmktime(23, 59, 59, $ymd[1], $ymd[2], $ymd[0]) - $off * 3600;
	}

	/* ---------- 查詢條件（§6.1） ---------- */

	/**
	 * 組出 WHERE 片段與參數（不含 keyset 游標）。
	 * $args 由呼叫端接著串上游標與 LIMIT。
	 * 回 ['where' => '...', 'args' => [...]]，args 以 'forum_thread' 為首（供 %t）。
	 */
	public static function build_where($fid, $include_recycle, $from_ts, $to_ts) {
		$where = 'fid=%d AND displayorder '.($include_recycle ? 'IN (0,-1)' : '= 0').' AND closed<=1';
		$args = ['forum_thread', (int)$fid];
		if($from_ts !== null) {
			$where .= ' AND dateline>=%d';
			$args[] = (int)$from_ts;
		}
		if($to_ts !== null) {
			$where .= ' AND dateline<=%d';
			$args[] = (int)$to_ts;
		}
		return ['where' => $where, 'args' => $args];
	}

	/** 符合條件的主題總數（§8-5，預覽用 COUNT，不跑全表）。 */
	public static function count_threads($fid, $include_recycle, $from_ts, $to_ts) {
		$c = self::build_where($fid, $include_recycle, $from_ts, $to_ts);
		return (int)DB::result_first('SELECT COUNT(*) FROM %t WHERE '.$c['where'], $c['args']);
	}

	/**
	 * 撈一批主題（keyset 游標，不用 OFFSET，§8-1）。
	 * $cursor = null 為第一批；否則 ['dateline'=>, 'tid'=>]，撈比它更舊的。
	 */
	public static function fetch_batch($fid, $include_recycle, $from_ts, $to_ts, $cursor, $limit) {
		$c = self::build_where($fid, $include_recycle, $from_ts, $to_ts);
		$where = $c['where'];
		$args  = $c['args'];
		if($cursor !== null) {
			$where .= ' AND (dateline<%d OR (dateline=%d AND tid<%d))';
			$args[] = (int)$cursor['dateline'];
			$args[] = (int)$cursor['dateline'];
			$args[] = (int)$cursor['tid'];
		}
		$limit = (int)$limit;
		$sql = 'SELECT tid, subject, author, authorid, dateline, lastpost, replies, views, typeid '
			.'FROM %t WHERE '.$where.' ORDER BY dateline DESC, tid DESC LIMIT '.$limit;
		return DB::fetch_all($sql, $args);
	}

	/* ---------- 佔位符替換（§5） ---------- */

	/**
	 * 為一列主題組出替換對照表。$n 為流水號、$fname 為（已還原的）版塊名、
	 * $classmap 為分類表、$siteurl 有尾斜線、$offset 為站台時區偏移。
	 * 只認 {A}~{K} 與 {N}；未定義者不入表 → strtr 原樣保留。
	 */
	public static function row_map($row, $n, $fname, $classmap, $siteurl, $offset) {
		$typeid = (int)$row['typeid'];
		return [
			'{A}' => self::decode_entities($row['subject']),
			'{B}' => (string)(int)$row['tid'],
			'{C}' => self::decode_entities($row['author']),
			'{D}' => (string)(int)$row['authorid'],
			'{E}' => dgmdate((int)$row['dateline'], 'Y-m-d H:i', $offset),
			'{F}' => (string)(int)$row['replies'],
			'{G}' => (string)(int)$row['views'],
			'{H}' => isset($classmap[$typeid]) ? $classmap[$typeid] : '',
			'{I}' => $siteurl.'forum.php?mod=viewthread&tid='.(int)$row['tid'],
			'{J}' => dgmdate((int)$row['lastpost'], 'Y-m-d H:i', $offset),
			'{K}' => $fname,
			'{N}' => (string)$n,
		];
	}

	/**
	 * 把一列主題套進（已依換行拆好的）樣板行。回這一筆的輸出字串（含尾端換行）。
	 * $tplLines：樣板拆行後的陣列；$eol：使用者選的換行符；$blankline：每筆之間空一行。
	 */
	public static function render_record($tplLines, $map, $eol, $blankline) {
		$parts = [];
		foreach($tplLines as $ln) {
			$parts[] = strtr($ln, $map);
		}
		$out = implode($eol, $parts).$eol;
		if($blankline) {
			$out .= $eol;
		}
		return $out;
	}

	/** 把樣板正規化拆成邏輯行（\r\n / \r / \n 皆收，§7）。 */
	public static function split_template($tpl) {
		return preg_split('/\r\n|\r|\n/', (string)$tpl);
	}

	/* ---------- 檔名（§7、§4.2） ---------- */

	public static function filename($fid) {
		return 'threadexport_fid'.(int)$fid.'_'.dgmdate(TIMESTAMP, 'Ymd-Hi', self::site_offset()).'.txt';
	}
}
