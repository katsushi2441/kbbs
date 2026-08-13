<?php
/* kbbs_geo_mailer — kbbsに投稿されたURLを ktrackgeo で SEO/GEO/AEO 診断し、
 * 投稿者（同一ドメインメール＝会社のご本人）へ結果と改善提案をメールで送る。
 * メール本文から ktrackgeo(App Store) / バイブプロト制作 / 販売代理店 へ導線をつなぐ。
 *
 * 実行: cron等で定期実行（本番は systemd user timer）。未送信の投稿だけ処理する。
 *   php kbbs_geo_mailer.php           # 未処理分を診断＆送信
 *   php kbbs_geo_mailer.php --dry     # 送信せず内容を表示（テスト）
 *
 * 依存: ktrackgeo の ktrackgeo_geo.php（ktg_geo_audit）。SMTPは aixec/.env。
 * (c) EXBRIDGE, Inc. / MIT License
 */
date_default_timezone_set('Asia/Tokyo');
$DRY = in_array('--dry', $argv);

$KBBS_LOG = __DIR__ . '/kbbs_posts.log.php';
$SENT_LOG = __DIR__ . '/kbbs_geo_sent.log.php';   // 送信済み投稿id（重複送信防止）
$GEO_LIB  = getenv('KTG_GEO_LIB') ?: '/home/kojima/work/ktrackgeo/public/ktrackgeo_geo.php';
$ENV_FILE = getenv('KBBS_ENV_FILE') ?: '/home/kojima/work/aixec/.env';

require_once $GEO_LIB;

/* ---- .env読み込み（SMTP） ---- */
function load_env($path) {
    $env = array();
    if (!is_file($path)) { return $env; }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#' || strpos($ln, '=') === false) { continue; }
        list($k, $v) = explode('=', $ln, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
    return $env;
}
$ENV = load_env($ENV_FILE);
$SMTP_HOST = $ENV['SMTP_HOST'] ?? 'mail18.heteml.jp';
$SMTP_PORT = (int)($ENV['SMTP_PORT'] ?? 465);
$SMTP_USER = $ENV['SMTP_FROM'] ?? '';

/* ---- 実投稿はサーバー(heteml)側のkbbs_posts.log.phpに保存される。
 * ローカルの空ファイルではなく、サーバーの投稿ログをFTPで取得してから処理する。
 * 送信済み(kbbs_geo_sent.log.php)はローカルに永続させ、再送を防ぐ。 ---- */
$REMOTE_PATH = getenv('KBBS_REMOTE_LOG') ?: '/web/kurage_exbridge_jp/kbbs_posts.log.php';
if (!empty($ENV['FTP_HOST']) && !empty($ENV['FTP_USER']) && !empty($ENV['FTP_PASS'])) {
    $ftp_url = sprintf('ftp://%s:%s@%s%s',
        rawurlencode($ENV['FTP_USER']), rawurlencode($ENV['FTP_PASS']), $ENV['FTP_HOST'], $REMOTE_PATH);
    $tmp = sys_get_temp_dir() . '/kbbs_posts_remote.log.php';
    $ch = curl_init($ftp_url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40));
    $data = curl_exec($ch);
    $ok = ($data !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400 && strpos($data, '<?php') === 0);
    curl_close($ch);
    if ($ok) { file_put_contents($tmp, $data); $KBBS_LOG = $tmp; }
    else { fwrite(STDERR, "警告: サーバー投稿ログを取得できず。ローカルを使用。\n"); }
}
$SMTP_PASS = $ENV['SMTP_PASSWORD'] ?? '';
$FROM_NAME = 'Kurage（株式会社エクスブリッジ）';

/* ---- 投稿・送信済みの読み書き ---- */
function read_jsonl($path) {
    $out = array();
    if (!is_file($path)) { return $out; }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        if (strpos($ln, '<?php') === 0) { continue; }
        $p = json_decode($ln, true);
        if (is_array($p)) { $out[] = $p; }
    }
    return $out;
}
function mark_sent($path, $id) {
    if (!is_file($path)) { @file_put_contents($path, "<?php exit; ?>\n", LOCK_EX); }
    @file_put_contents($path, json_encode(array('id' => $id, 'ts' => time())) . "\n", FILE_APPEND | LOCK_EX);
}

/* ---- 生SMTP（SSL/465, AUTH LOGIN）。外部ライブラリなし ---- */
function smtp_send($host, $port, $user, $pass, $from_name, $to, $subject, $body) {
    $fp = @stream_socket_client("ssl://$host:$port", $eno, $estr, 20);
    if (!$fp) { return "接続失敗: $estr"; }
    $read = function() use ($fp) { $d = ''; while ($line = fgets($fp, 512)) { $d .= $line; if (isset($line[3]) && $line[3] === ' ') break; } return $d; };
    $cmd  = function($c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };
    $read();
    $cmd("EHLO kurage.exbridge.jp");
    $cmd("AUTH LOGIN"); $cmd(base64_encode($user)); $r = $cmd(base64_encode($pass));
    if (strpos($r, '235') === false) { fclose($fp); return "認証失敗: " . trim($r); }
    $cmd("MAIL FROM:<$user>");
    $r = $cmd("RCPT TO:<$to>");
    if ($r[0] !== '2') { fclose($fp); return "宛先拒否: " . trim($r); }
    $cmd("DATA");
    $headers  = "From: " . mb_encode_mimeheader($from_name) . " <$user>\r\n";
    $headers .= "To: <$to>\r\n";
    $headers .= "Subject: " . mb_encode_mimeheader($subject) . "\r\n";
    $headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    $body = preg_replace('/^\./m', '..', $body);   // ドット詰め
    $r = $cmd($headers . "\r\n" . $body . "\r\n.");
    $cmd("QUIT"); fclose($fp);
    return (strpos($r, '250') !== false) ? '' : "送信失敗: " . trim($r);
}

/* ---- メール文面（診断結果＋提案＋商品・サービス導線） ---- */
function build_mail($post, $audit) {
    $host = parse_url($post['url'], PHP_URL_HOST);
    $score = (int)$audit['score'];
    $band_ja = array('good' => '良好', 'fair' => '要改善', 'weak' => '弱い', 'critical' => '危険')[$audit['band']] ?? $audit['band'];
    $cat_ja = ktg_geo_categories();
    $CAT_MAX = array('robots'=>20,'llms'=>12,'schema'=>16,'meta'=>15,'content'=>15,'signals'=>10,'ai_discovery'=>10,'brand_entity'=>10);
    $lines = array();
    foreach ($audit['categories'] as $k => $pt) {
        $max = $CAT_MAX[$k] ?? 12;
        $bar_len = 10; $filled = $max > 0 ? (int)round($pt / $max * $bar_len) : 0;
        $bar = str_repeat('■', $filled) . str_repeat('□', $bar_len - $filled);
        $lines[] = sprintf("  %s %s  %2d/%d", $bar, ($cat_ja[$k] ?? $k), $pt, $max);
    }
    $cats = implode("\n", $lines);
    $recs = '';
    foreach (array_slice($audit['recommendations'], 0, 5) as $i => $rec) {
        $recs .= "  " . ($i + 1) . ". " . $rec . "\n";
    }
    if ($recs === '') { $recs = "  目立った不足はありませんでした。現状を維持しつつ、更新頻度と被リンクを増やすと有利です。\n"; }

    $subject = "【SEO/GEO/AEO診断】{$host} は、AIに読まれる状態ですか？（スコア {$score}/100）";

    $body = <<<EOT
{$post['title']}
のご投稿ありがとうございます。Kurage BBS 運営（株式会社エクスブリッジ）です。

ご投稿いただいたURL {$post['url']} を、
AI検索・生成エンジンに「読まれ、引用される」状態になっているか、8つの観点で自動診断しました。
※判定はすべて事実確認（タグの有無・許可設定など）で、生成AIは使っていません。何度実行しても同じ結果になります。

────────────────────────────
■ 総合スコア： {$score} / 100（{$band_ja}）
────────────────────────────
{$cats}

■ いま優先して直すとよい点
{$recs}
────────────────────────────

▼ なぜこれが大事か
Google Analytics は GPTBot や ClaudeBot などのAIクローラーを除外します。
つまり「AIに読まれているか」は、普通のアクセス解析では見えません。
これからの集客は、人だけでなく "AIに正しく引用される" ことが土台になります。

──────────────────────────────────
◆ 続けて「実測」もしたい方へ — Kurage Track & GEO（ktrackgeo）
──────────────────────────────────
今回の診断（読まれる状態か）に加えて、実際にAIクローラーが自社サイトに来たかを
記録・可視化できる自社設置ツールを販売しています。買い切り・ソース同梱・データベース不要。
・商品ページ： https://kappstore.exbridge.jp/app.php?id=48ca584977698dcc
 （買い切り 55,000円・MITライセンス・共有レンタルサーバーで動作）

──────────────────────────────────
◆ 「直すところまで任せたい」方へ
──────────────────────────────────
llms.txt や構造化データの追加、AIに引用されやすいページ改善は、
AIで動くシステムを最短1営業日から制作する当社のサービスでお手伝いできます。
・バイブプロトタイプ制作： https://kurage.exbridge.jp/vibe-prototype.html?ref=geomail
・まずは無料でAIに相談： https://kurage.exbridge.jp/chat.php?ref=geomail

──────────────────────────────────
◆ お知り合いに紹介して収益化したい方へ
──────────────────────────────────
「AIに強いサイトにしたい」会社をご紹介いただくと、成果報酬をお支払いする
販売パートナー制度（登録無料・ノルマなし）もあります。
・販売代理店になる： https://kurage.exbridge.jp/reseller.html?ref=geomail

────────────────────────────
このメールは、Kurage BBS（https://kurage.exbridge.jp/kbbs.php）に
{$host} のURLをご投稿いただいた方へ、診断結果としてお送りしています。
今後の診断メールが不要な場合は、このメールにご返信ください。

株式会社エクスブリッジ（名古屋市名東区藤が丘130番地）
https://exbridge.jp/
EOT;
    return array($subject, $body);
}

/* ---- メイン ---- */
$posts = read_jsonl($KBBS_LOG);
$sent  = array();
foreach (read_jsonl($SENT_LOG) as $s) { $sent[$s['id']] = true; }

$done = 0; $skip = 0; $fail = 0;
foreach ($posts as $post) {
    if (empty($post['id']) || empty($post['url']) || empty($post['email'])) { continue; }
    if (isset($sent[$post['id']])) { $skip++; continue; }

    $audit = ktg_geo_audit($post['url']);
    if (!empty($audit['error'])) {
        fwrite(STDERR, "診断失敗 {$post['url']}: {$audit['error']}\n");
        mark_sent($SENT_LOG, $post['id']);   // 取得不能URLは再試行しない
        $fail++; continue;
    }
    list($subject, $body) = build_mail($post, $audit);

    if ($DRY) {
        echo "== TO: {$post['email']} / score {$audit['score']} ==\n$subject\n\n" . mb_substr($body, 0, 500) . "\n...\n\n";
        $done++; continue;
    }
    $err = smtp_send($SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS, $FROM_NAME, $post['email'], $subject, $body);
    if ($err === '') {
        mark_sent($SENT_LOG, $post['id']);
        fwrite(STDERR, "送信OK {$post['email']} (score {$audit['score']})\n");
        $done++;
    } else {
        fwrite(STDERR, "送信NG {$post['email']}: $err\n");
        $fail++;
    }
    sleep(2);   // 連続送信のレート抑制
}
fwrite(STDERR, "完了: 送信$done / 既送信$skip / 失敗$fail\n");
