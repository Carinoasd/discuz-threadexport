<?php

/**
 * threadexport — 後台頁（管理中心 → 應用 → 插件 → 主題列表導出）。
 *
 * 流程（§4）：填 fid → 貼樣板 → 選條件 → 預覽 → 下載 TXT。
 * 這個檔是控制流：權限 → CSRF → 驗證 → 預覽或分批串流下載。
 * 純查詢邏輯與替換在 threadexport.class.php；文案在 lang.php。
 *
 * 只讀：整個檔只有 SELECT，無任何 INSERT/UPDATE/DELETE。
 * 下載時沿用 X5 後台既有的檔案輸出慣例（見 source/app/admin/child/members/export.php）：
 *   define('FOOTERDISABLED') 擋掉 cpfooter → 清掉所有 ob 層 → 送標頭 → 邊撈邊 echo/flush。
 *
 * 作者：Carinoasd
 * (C) 2026 Carinoasd
 */

if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) {
	exit('Access Denied');
}

require_once DISCUZ_ROOT.'./source/plugin/threadexport/threadexport.class.php';

/* ---------- §3 兩層權限（在任何資料查詢之前） ---------- */
if(!threadexport::can_use($_G['uid'])) {
	cpmsg(threadexport::L('err_no_perm'), '', 'error');
}

$pluginid = getgpc('do');

function te_h($s) {
	return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * 把目前提交／預設的欄位讀成一個陣列。
 * 未提交（首次載入）用設定的預設值；提交後回填使用者輸入。
 */
function te_input() {
	$submitted = getgpc('previewsubmit') || getgpc('exportsubmit');
	$cfg = threadexport::config();
	return [
		'submitted' => $submitted,
		'fid'       => (int)getgpc('fid'),
		'template'  => $submitted ? (string)getgpc('template') : '{B} {A}',
		'recycle'   => $submitted ? (getgpc('recycle') ? 1 : 0) : 0,
		'date_from' => $submitted ? trim((string)getgpc('date_from')) : '',
		'date_to'   => $submitted ? trim((string)getgpc('date_to')) : '',
		'bom'       => $submitted ? (getgpc('bom') ? 1 : 0) : (int)$cfg['default_bom'],
		'crlf'      => $submitted ? (getgpc('eol') === 'lf' ? 0 : 1) : (int)$cfg['default_crlf'],
		'blank'     => $submitted ? (getgpc('blank') ? 1 : 0) : 0,
	];
}

/**
 * 驗證輸入。回 [ok(bool), err(string或''), from_ts, to_ts]。
 * from_ts/to_ts 為 null 表示不限。
 */
function te_validate($in) {
	$tpl = $in['template'];
	if($tpl === '' || trim($tpl) === '') {
		return [false, threadexport::L('err_tpl_empty'), null, null];
	}
	if(mb_strlen($tpl, 'UTF-8') > threadexport::TPL_MAX) {
		return [false, threadexport::L('err_tpl_long', ['max' => threadexport::TPL_MAX]), null, null];
	}
	$from = threadexport::parse_date($in['date_from']);
	if($from === false) {
		return [false, threadexport::L('err_date_from'), null, null];
	}
	$to = threadexport::parse_date($in['date_to']);
	if($to === false) {
		return [false, threadexport::L('err_date_to'), null, null];
	}
	$from_ts = $from ? threadexport::day_start_ts($from) : null;
	$to_ts   = $to   ? threadexport::day_end_ts($to)     : null;
	if($from_ts !== null && $to_ts !== null && $from_ts > $to_ts) {
		return [false, threadexport::L('err_date_order'), null, null];
	}
	return [true, '', $from_ts, $to_ts];
}

/* ================= 分支 A：AJAX 即時查版塊名（§4.1-1，唯讀） ================= */
if(getgpc('te_ajax') === 'fidcheck') {
	$forum = threadexport::fetch_forum((int)getgpc('fid'));
	$ok = threadexport::forum_exportable($forum);
	$name = $ok ? threadexport::decode_entities($forum['name']) : '';
	define('FOOTERDISABLED', true);
	while(ob_get_level() > 0) { @ob_end_clean(); }
	@header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['ok' => $ok, 'name' => $name], JSON_UNESCAPED_UNICODE);
	exit();
}

/* ================= 分支 B：下載（§4.2、§8、§10） ================= */
if(submitcheck('exportsubmit')) {
	$in = te_input();
	[$ok, $err, $from_ts, $to_ts] = te_validate($in);
	if(!$ok) {
		cpmsg($err, '', 'error');
	}
	$forum = threadexport::fetch_forum($in['fid']);
	if(!threadexport::forum_exportable($forum)) {
		cpmsg(threadexport::L('err_bad_fid'), '', 'error');
	}

	$fid     = $in['fid'];
	$recycle = $in['recycle'];
	$tpl     = $in['template'];
	$bom     = $in['bom'];
	$eol     = $in['crlf'] ? "\r\n" : "\n";
	$blank   = $in['blank'];
	$batch   = threadexport::batch_size();

	// 先解除時間限制（含第一批查詢與整趟串流都不受 max_execution_time 影響），使用者取消即停。
	@set_time_limit(0);
	ignore_user_abort(false);

	// §10-8：先把第一批撈好，成功才送下載標頭；失敗顯示乾淨錯誤頁、不吐半截檔。
	try {
		$firstBatch = threadexport::fetch_batch($fid, $recycle, $from_ts, $to_ts, null, $batch);
	} catch(Throwable $e) {
		cpmsg('查詢失敗，未輸出任何檔案。', '', 'error');
	}

	// 提交下載：擋 cpfooter、清 ob、送標頭。
	define('FOOTERDISABLED', true);
	while(ob_get_level() > 0) { @ob_end_clean(); }
	@header('Content-Encoding: none');
	@header('Content-Type: text/plain; charset=utf-8');
	@header('Content-Disposition: attachment; filename="'.threadexport::filename($fid).'"');
	@header('Pragma: no-cache');
	@header('Expires: 0');
	@header('Cache-Control: no-store, no-cache, must-revalidate');

	if($bom) {
		echo "\xEF\xBB\xBF";
	}

	$tplLines = threadexport::split_template($tpl);
	$classmap = threadexport::threadclass_map($fid);
	$siteurl  = $_G['siteurl'];
	$offset   = threadexport::site_offset();
	$fname    = threadexport::decode_entities($forum['name']);

	$n = 0;
	$rows = $firstBatch;
	unset($firstBatch); // 任一時刻只持有一批（§8-4），不留第一批的重複參照
	while($rows) {
		$buf = '';
		$cursor = null;
		foreach($rows as $row) {
			$n++;
			$map = threadexport::row_map($row, $n, $fname, $classmap, $siteurl, $offset);
			$buf .= threadexport::render_record($tplLines, $map, $eol, $blank);
			$cursor = ['dateline' => $row['dateline'], 'tid' => $row['tid']];
		}
		echo $buf;
		flush();
		if(count($rows) < $batch) {
			break;
		}
		$rows = threadexport::fetch_batch($fid, $recycle, $from_ts, $to_ts, $cursor, $batch);
		unset($buf);
	}
	exit();
}

/* ================= 分支 C：預覽 + 表單（§4.1、§4.3） ================= */
$in = te_input();
$notice = '';       // 紅字提示
$preview = null;    // ['total'=>int, 'text'=>string]

if(submitcheck('previewsubmit')) {
	[$ok, $err, $from_ts, $to_ts] = te_validate($in);
	if(!$ok) {
		$notice = $err;
	} else {
		$forum = threadexport::fetch_forum($in['fid']);
		if(!threadexport::forum_exportable($forum)) {
			$notice = threadexport::L('err_bad_fid');
		} else {
			$total = threadexport::count_threads($in['fid'], $in['recycle'], $from_ts, $to_ts);
			$rows = threadexport::fetch_batch($in['fid'], $in['recycle'], $from_ts, $to_ts, null, 20);
			$eol = $in['crlf'] ? "\r\n" : "\n";
			$tplLines = threadexport::split_template($in['template']);
			$classmap = threadexport::threadclass_map($in['fid']);
			$siteurl  = $_G['siteurl'];
			$offset   = threadexport::site_offset();
			$fname    = threadexport::decode_entities($forum['name']);
			$text = '';
			$n = 0;
			foreach($rows as $row) {
				$n++;
				$map = threadexport::row_map($row, $n, $fname, $classmap, $siteurl, $offset);
				$text .= threadexport::render_record($tplLines, $map, $eol, $in['blank']);
			}
			$preview = ['total' => $total, 'text' => $text];
		}
	}
}

/* ---------- 版塊名（伺服器端先算一次，供無 JS 時也可見） ---------- */
$forumName = '';
$forumBad  = false;
if($in['fid'] > 0) {
	$f = threadexport::fetch_forum($in['fid']);
	if(threadexport::forum_exportable($f)) {
		$forumName = threadexport::decode_entities($f['name']);
	} else {
		$forumBad = true;
	}
}

$BASE = ADMINSCRIPT.'?action=plugins&operation=config&do='.rawurlencode($pluginid).'&identifier=threadexport&pmod=admincp';
$cfg = threadexport::config();

?>
<style>
.te-wrap{margin:10px;max-width:1100px;font-size:13px;line-height:1.7}
.te-wrap textarea{width:100%;box-sizing:border-box;font-family:Consolas,'Courier New',monospace;font-size:13px}
.te-row{margin:10px 0}
.te-row label.k{display:inline-block;min-width:96px;font-weight:bold;vertical-align:top}
.te-hint{color:#888;font-size:12px}
.te-bad{color:#c00;font-weight:bold}
.te-ok{color:#093;font-weight:bold}
.te-cols{display:flex;gap:18px;flex-wrap:wrap}
.te-cols .c1{flex:1 1 520px;min-width:340px}
.te-cols .c2{flex:0 0 300px}
.te-ph{border-collapse:collapse;font-size:12px}
.te-ph th,.te-ph td{border:1px solid #ddd;padding:2px 6px;text-align:left}
.te-ph th{background:#f4f7fb}
.te-btn{padding:4px 14px;margin-right:8px}
.te-notice{margin:10px;padding:8px 10px;border:1px solid #f0c0c0;background:#fff5f5;color:#c00}
.te-foot{margin:16px 10px;color:#999;font-size:12px;border-top:1px solid #eee;padding-top:8px}
.te-cfg{font-size:12px;color:#555;margin:8px 0}
.te-cfg b{color:#333}
</style>
<div class="te-wrap">
<h3 style="margin:4px 0 12px"><?php echo te_h(threadexport::L('page_title')); ?></h3>

<?php if($notice !== '') { ?>
<div class="te-notice"><?php echo te_h($notice); ?></div>
<?php } ?>

<form method="post" action="<?php echo te_h(ADMINSCRIPT); ?>" id="te_form">
<input type="hidden" name="action" value="plugins" />
<input type="hidden" name="operation" value="config" />
<input type="hidden" name="do" value="<?php echo te_h($pluginid); ?>" />
<input type="hidden" name="identifier" value="threadexport" />
<input type="hidden" name="pmod" value="admincp" />
<input type="hidden" name="formhash" value="<?php echo FORMHASH; ?>" />

<div class="te-cols">
<div class="c1">

	<div class="te-row">
		<label class="k"><?php echo te_h(threadexport::L('lb_fid')); ?></label>
		<input type="text" name="fid" id="te_fid" size="8" value="<?php echo $in['fid'] ? (int)$in['fid'] : ''; ?>" autocomplete="off" />
		<span id="te_fidname" class="<?php echo $forumBad ? 'te-bad' : 'te-ok'; ?>"><?php
			if($forumBad) { echo te_h(threadexport::L('hint_forum_no')); }
			elseif($forumName !== '') { echo te_h(threadexport::L('hint_forum_ok', ['name' => $forumName])); }
		?></span>
	</div>

	<div class="te-row">
		<label class="k"><?php echo te_h(threadexport::L('lb_template')); ?></label>
		<div class="te-hint"><?php echo te_h(threadexport::L('hint_tpl_help')); ?></div>
		<textarea name="template" id="te_tpl" rows="6"><?php echo te_h($in['template']); ?></textarea>
	</div>

	<div class="te-row">
		<label class="k"><?php echo te_h(threadexport::L('lb_quick')); ?></label>
		<button type="button" class="btn" onclick="teSet('{A}')"><?php echo te_h(threadexport::L('q_title')); ?></button>
		<button type="button" class="btn" onclick="teSet('{B} {A}')"><?php echo te_h(threadexport::L('q_tidtitle')); ?></button>
		<button type="button" class="btn" onclick="teSet('[URL=tid={B}]{A}[/URL]')"><?php echo te_h(threadexport::L('q_url')); ?></button>
	</div>

	<div class="te-row">
		<label class="k"><?php echo te_h(threadexport::L('lb_filter')); ?></label>
		<div><label><input type="checkbox" name="recycle" value="1"<?php echo $in['recycle'] ? ' checked' : ''; ?> /> <?php echo te_h(threadexport::L('lb_recycle')); ?></label></div>
		<div style="margin-top:6px">
			<?php echo te_h(threadexport::L('lb_datefrom')); ?>
			<input type="text" name="date_from" size="12" placeholder="<?php echo te_h(threadexport::L('lb_dateph')); ?>" value="<?php echo te_h($in['date_from']); ?>" />
			<?php echo te_h(threadexport::L('lb_dateto')); ?>
			<input type="text" name="date_to" size="12" placeholder="<?php echo te_h(threadexport::L('lb_dateph')); ?>" value="<?php echo te_h($in['date_to']); ?>" />
			<span class="te-hint"><?php echo te_h(threadexport::L('hint_dateblank')); ?></span>
		</div>
	</div>

	<div class="te-row">
		<label class="k"><?php echo te_h(threadexport::L('lb_output')); ?></label>
		<div><label><input type="checkbox" name="bom" value="1"<?php echo $in['bom'] ? ' checked' : ''; ?> /> <?php echo te_h(threadexport::L('lb_bom')); ?></label>
			<span class="te-hint"><?php echo te_h(threadexport::L('hint_bom')); ?></span></div>
		<div style="margin-top:6px"><?php echo te_h(threadexport::L('lb_eol')); ?>：
			<label><input type="radio" name="eol" value="crlf"<?php echo $in['crlf'] ? ' checked' : ''; ?> /> <?php echo te_h(threadexport::L('lb_crlf')); ?></label>
			<label style="margin-left:10px"><input type="radio" name="eol" value="lf"<?php echo $in['crlf'] ? '' : ' checked'; ?> /> <?php echo te_h(threadexport::L('lb_lf')); ?></label>
		</div>
		<div style="margin-top:6px"><label><input type="checkbox" name="blank" value="1"<?php echo $in['blank'] ? ' checked' : ''; ?> /> <?php echo te_h(threadexport::L('lb_blank')); ?></label>
			<span class="te-hint"><?php echo te_h(threadexport::L('hint_blank')); ?></span></div>
	</div>

	<div class="te-row">
		<button type="submit" class="btn te-btn" name="previewsubmit" value="1"><?php echo te_h(threadexport::L('btn_preview')); ?></button>
		<button type="submit" class="btn te-btn" name="exportsubmit" value="1" id="te_dl"<?php echo $forumBad ? ' disabled' : ''; ?>><?php echo te_h(threadexport::L('btn_download')); ?></button>
	</div>

</div>
<div class="c2">
	<table class="te-ph">
		<thead><tr><th><?php echo te_h(threadexport::L('ph_head_ph')); ?></th><th><?php echo te_h(threadexport::L('ph_head_desc')); ?></th></tr></thead>
		<tbody>
		<?php foreach(['A','B','C','D','E','F','G','H','I','J','K','N'] as $L) { ?>
			<tr><td><code>{<?php echo $L; ?>}</code></td><td><?php echo te_h(threadexport::L('ph_'.$L)); ?></td></tr>
		<?php } ?>
		</tbody>
	</table>
	<div class="te-cfg">
		<b><?php echo te_h(threadexport::L('cfg_title')); ?></b><br />
		<?php echo te_h(threadexport::L('cfg_allow')); ?>：<?php echo $cfg['allow_uids'] !== '' ? te_h($cfg['allow_uids']) : te_h(threadexport::L('cfg_empty')); ?><br />
		<?php echo te_h(threadexport::L('cfg_batch')); ?>：<?php echo (int)threadexport::batch_size(); ?><br />
		<?php echo te_h(threadexport::L('cfg_bom')); ?>：<?php echo $cfg['default_bom'] ? te_h(threadexport::L('cfg_yes')) : te_h(threadexport::L('cfg_no')); ?><br />
		<?php echo te_h(threadexport::L('cfg_crlf')); ?>：<?php echo $cfg['default_crlf'] ? te_h(threadexport::L('cfg_win')) : te_h(threadexport::L('cfg_nix')); ?><br />
		<span class="te-hint"><?php echo te_h(threadexport::L('cfg_edit_hint')); ?></span>
	</div>
</div>
</div>
</form>

<?php if($preview !== null) { ?>
<div class="te-row" style="margin:16px 10px">
	<div><b><?php echo $preview['total'] > 0
		? te_h(threadexport::L('pv_count', ['n' => $preview['total']]))
		: te_h(threadexport::L('pv_zero')); ?></b></div>
	<?php if($preview['total'] > 0) { ?>
	<div class="te-hint" style="margin-top:6px"><?php echo te_h(threadexport::L('pv_first20')); ?></div>
	<textarea rows="14" readonly onclick="this.select()"><?php echo te_h($preview['text']); ?></textarea>
	<?php } ?>
</div>
<?php } ?>

<div class="te-foot"><?php echo te_h(threadexport::L('footer')); ?></div>
</div>

<script type="text/javascript">
function teSet(v){ var t=document.getElementById('te_tpl'); if(t){ t.value=v; } }
(function(){
	var fid=document.getElementById('te_fid'), out=document.getElementById('te_fidname'), dl=document.getElementById('te_dl');
	if(!fid){ return; }
	function check(){
		var v=(fid.value||'').replace(/[^0-9]/g,'');
		if(!v){ out.textContent=''; out.className=''; if(dl){dl.disabled=false;} return; }
		var url='<?php echo $BASE; ?>&te_ajax=fidcheck&fid='+encodeURIComponent(v)+'&t='+(+new Date());
		var x=new XMLHttpRequest();
		x.open('GET', url, true);
		x.onreadystatechange=function(){
			if(x.readyState!==4){ return; }
			try{
				var r=JSON.parse(x.responseText);
				if(r.ok){ out.textContent='<?php echo addslashes(threadexport::L('hint_forum_ok', ['name' => ''])); ?>'+r.name; out.className='te-ok'; if(dl){dl.disabled=false;} }
				else{ out.textContent='<?php echo addslashes(threadexport::L('hint_forum_no')); ?>'; out.className='te-bad'; if(dl){dl.disabled=true;} }
			}catch(e){ out.textContent=''; out.className=''; }
		};
		x.send();
	}
	fid.addEventListener('blur', check);
	fid.addEventListener('change', check);
})();
</script>
<?php
// 頁面自成一頁：由 config.php 的 shownav/showsubmenu 起頭，這裡輸出主體，cpfooter 收尾。
