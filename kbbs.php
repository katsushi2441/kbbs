<?php
/* kbbs — 宣伝OKの1ファイル掲示板（Kurage BBS）
 *
 * 会社・お店・サービスの宣伝を「歓迎」する掲示板。ホームページへのリンク投稿OK
 * （バックリンクになります）。荒らし・なりすまし対策として、
 *   1) 𝕏（旧Twitter）ログイン必須
 *   2) 宣伝するURLと同じドメインのメールアドレスが必要（その会社の方しか投稿できない）
 * という2段の本人性チェックを行う。
 *
 * 1ファイルPHP・DBなし（同ディレクトリのファイルに保存）・MITライセンス。
 * 認証は auth_common.php（url2ai_auth_*）を利用。
 * (c) EXBRIDGE, Inc. / MIT License
 */
/* ---- 認証 ----
 * 同ディレクトリに auth_common.php（X OAuthブートストラップ）があれば「𝕏ログイン必須」モード。
 * 無ければスタンドアロンモード（誰でも投稿フォームは開けるが、投稿には
 * 宣伝URLと同一ドメインのメールアドレスが必須＝本人性チェックはそのまま効く）。 */
if (!defined('KBBS_ADMIN_KEY')) { define('KBBS_ADMIN_KEY', 'change-me'); }  // スタンドアロン時の管理画面キー（?admin=1&key=〜）。設置したら必ず変更。
$KBBS_X = file_exists(__DIR__ . '/auth_common.php');
if ($KBBS_X) {
    require_once __DIR__ . '/auth_common.php';
    if (isset($_GET['login']))  { header('Location: ' . url2ai_auth_login_url('/kbbs.php'));  exit; }
    if (isset($_GET['logout'])) { header('Location: ' . url2ai_auth_logout_url('/kbbs.php')); exit; }
    $auth = url2ai_auth_bootstrap();
    $logged_in = !empty($auth['logged_in']);
    $user = $logged_in ? trim((string)$auth['session_user']) : '';
    $is_admin = $logged_in && !empty($auth['is_admin']);
} else {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    $logged_in = true;
    $user = '';
    $is_admin = isset($_GET['key']) && KBBS_ADMIN_KEY !== 'change-me'
        && hash_equals(KBBS_ADMIN_KEY, (string)$_GET['key']);
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---- 保存。先頭行の <?php exit; でWeb直読みを防ぐ（メールアドレスを含むため） ---- */
$KBBS_LOG = __DIR__ . '/kbbs_posts.log.php';
function kbbs_all() {
    global $KBBS_LOG;
    $out = array();
    if (!file_exists($KBBS_LOG)) { return $out; }
    foreach (file($KBBS_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        if (strpos($ln, '<?php') === 0) { continue; }
        $p = json_decode($ln, true);
        if (is_array($p) && !empty($p['title'])) { $out[] = $p; }
    }
    return $out;
}
function kbbs_append($post) {
    global $KBBS_LOG;
    if (!file_exists($KBBS_LOG)) { @file_put_contents($KBBS_LOG, "<?php exit; ?>\n", LOCK_EX); }
    @file_put_contents($KBBS_LOG, json_encode($post, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/* ---- URLとメールのドメイン一致チェック（宣伝URLの会社の方だけ投稿できる） ---- */
function kbbs_domain_ok($url, $email) {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);
    $at = strrchr($email, '@');
    if ($at === false) { return false; }
    $edom = strtolower(substr($at, 1));
    if ($host === '' || $edom === '') { return false; }
    if ($host === $edom) { return true; }
    if (substr($host, -strlen('.' . $edom)) === '.' . $edom) { return true; }  // shop.example.jp × example.jp
    if (substr($edom, -strlen('.' . $host)) === '.' . $host) { return true; }  // example.jp × mail.example.jp
    return false;
}

/* ---- CSRF ---- */
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['kbbs_csrf'])) {
    $_SESSION['kbbs_csrf'] = bin2hex(random_bytes(16));
}
$csrf = isset($_SESSION['kbbs_csrf']) ? $_SESSION['kbbs_csrf'] : '';

/* ---- 投稿処理 ---- */
$err = ''; $ok_msg = '';
if ($logged_in && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $t   = trim((string)($_POST['title'] ?? ''));
    $txt = trim((string)($_POST['text'] ?? ''));
    $url = trim((string)($_POST['url'] ?? ''));
    $eml = trim((string)($_POST['email'] ?? ''));
    if (($_POST['csrf'] ?? '') !== $csrf || $csrf === '') {
        $err = '送信を確認できませんでした。もう一度お試しください。';
    } elseif ($t === '' || mb_strlen($t, 'UTF-8') > 60) {
        $err = 'タイトルをご入力ください（60文字まで）。';
    } elseif ($txt === '' || mb_strlen($txt, 'UTF-8') > 1200) {
        $err = '本文をご入力ください（1,200文字まで）。';
    } elseif (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#', $url)) {
        $err = '宣伝するホームページのURL（https://〜）をご入力ください。';
    } elseif (!filter_var($eml, FILTER_VALIDATE_EMAIL)) {
        $err = 'メールアドレスをご入力ください。';
    } elseif (!kbbs_domain_ok($url, $eml)) {
        $err = 'メールアドレスは、宣伝するURLと同じドメインのものをご入力ください（例：URLが https://example.jp なら info@example.jp）。会社・サービスのご本人様であることの確認のためです。';
    } else {
        // 連投制限: 22時間に1回（Xログイン時=ユーザー単位／スタンドアロン=宣伝ドメイン単位）
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        foreach (array_reverse(kbbs_all()) as $p) {
            $same = ($user !== '') ? (($p['user'] ?? '') === $user) : (($p['host'] ?? '') === $host);
            if ($same && (time() - (int)$p['ts']) < 22 * 3600) {
                $err = '投稿は1日1回までです。また明日お願いします。'; break;
            }
        }
        if ($err === '') {
            kbbs_append(array(
                'id' => uniqid(), 'ts' => time(), 'user' => preg_replace('/[^0-9A-Za-z_]/', '', $user),
                'host' => $host, 'title' => $t, 'text' => $txt, 'url' => $url, 'email' => $eml,
            ));
            header('Location: kbbs.php?posted=1'); exit;
        }
    }
}
if (isset($_GET['posted'])) { $ok_msg = '投稿しました。掲載ありがとうございます！'; }

/* ---- 管理者: 投稿者一覧（メール・𝕏フォロー/DM＝営業の起点） ---- */
if ($is_admin && isset($_GET['admin'])) {
    $rows = array_reverse(kbbs_all());
    ?><!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>kbbs 投稿者一覧（管理）</title><style>
    body{font-family:"Hiragino Sans","Noto Sans JP",sans-serif;background:#f4faf9;color:#20323a;padding:20px;line-height:1.7}
    h1{font-size:20px;color:#0a5a54;margin-bottom:4px}
    p.mut{font-size:12.5px;color:#5b6f76;margin-bottom:14px}
    table{width:100%;border-collapse:collapse;background:#fff;font-size:13px}
    th{background:#eef7f6;color:#0a5a54;text-align:left;padding:7px 9px;border:1px solid #d5e6e3}
    td{padding:7px 9px;border:1px solid #d5e6e3;vertical-align:top}
    td img{width:30px;height:30px;border-radius:50%;vertical-align:middle;margin-right:6px}
    a.x{display:inline-block;background:#0f1419;color:#fff;text-decoration:none;font-weight:700;font-size:11.5px;border-radius:999px;padding:4px 10px;margin:2px 3px 2px 0}
    a.x.f{background:#0a726b}
    </style></head><body>
    <h1>kbbs 投稿者一覧（<?php echo count($rows); ?>件）</h1>
    <p class="mut">💡 URL＝会社サイト、メール＝同ドメイン確認済み。良さそうな会社は𝕏フォロー・DMでご提案を。</p>
    <table><tr><th>日時</th><th>𝕏</th><th>タイトル</th><th>会社URL</th><th>メール</th><th>アクション</th></tr>
    <?php foreach ($rows as $p): ?>
    <tr><td><?php echo h(date('m/d H:i', (int)$p['ts'])); ?></td>
    <td><?php if (!empty($p['user'])): ?><img src="https://unavatar.io/x/<?php echo rawurlencode($p['user']); ?>" alt="" loading="lazy">@<?php echo h($p['user']); ?><?php else: ?>（<?php echo h($p['host'] ?? ''); ?>）<?php endif; ?></td>
    <td><?php echo h($p['title']); ?></td>
    <td><a href="<?php echo h($p['url']); ?>" target="_blank" rel="noopener"><?php echo h(parse_url($p['url'], PHP_URL_HOST)); ?></a></td>
    <td><?php echo h($p['email']); ?></td>
    <td><a class="x" href="https://x.com/<?php echo rawurlencode($p['user']); ?>" target="_blank" rel="noopener">𝕏 / DM</a>
        <a class="x f" href="https://x.com/intent/user?screen_name=<?php echo rawurlencode($p['user']); ?>" target="_blank" rel="noopener">フォロー</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6">まだ投稿がありません。</td></tr><?php endif; ?>
    </table></body></html><?php
    exit;
}

/* ---- 表示用 ---- */
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 20;
$all = array_reverse(kbbs_all());
$total = count($all);
$posts = array_slice($all, ($page - 1) * $per, $per);
$pages = max(1, (int)ceil($total / $per));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kurage BBS（kbbs） — 宣伝OK・リンクOKの掲示板｜会社・お店・サービスを自由にPR</title>
<meta name="description" content="会社・お店・サービスの宣伝を歓迎する掲示板。ホームページへのリンク投稿OK。𝕏ログインと、宣伝するURLと同じドメインのメールアドレスで、ご本人様だけが投稿できる安心設計。名古屋のAIシステム開発会社エクスブリッジが運営。">
<link rel="canonical" href="https://kurage.exbridge.jp/kbbs.php">
<meta property="og:type" content="website">
<meta property="og:title" content="Kurage BBS — 宣伝OK・リンクOKの掲示板">
<meta property="og:description" content="会社・お店・サービスの宣伝を歓迎。ホームページへのリンク投稿OK。𝕏ログイン＋同一ドメインメールの本人確認つき。">
<meta property="og:url" content="https://kurage.exbridge.jp/kbbs.php">
<meta property="og:image" content="https://kurage.exbridge.jp/images/kbbs-ogp.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="https://kurage.exbridge.jp/images/kbbs-ogp.png">
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','G-BP0650KDFR');</script>
<script>(function(){var s=document.createElement('script');s.src='https://kurage.exbridge.jp/simpletrack.php?url='+encodeURIComponent(location.href)+'&ref='+encodeURIComponent(document.referrer);document.head.appendChild(s)})();</script>
<style>
:root{--teal:#0f7a72;--teal-d:#0a5a54;--teal-l:#12a99f;--bg:#f4faf9;--ink:#20323a}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Hiragino Sans","Noto Sans JP","Yu Gothic",sans-serif;background:var(--bg);color:var(--ink);line-height:1.85}
header{background:linear-gradient(135deg,var(--teal-d),var(--teal-l));color:#fff;padding:14px 18px;display:flex;justify-content:space-between;align-items:center}
header a{color:#fff;text-decoration:none;font-weight:900;font-size:17px}
header .u{font-size:12.5px;opacity:.9}
.hero{text-align:center;padding:34px 18px 8px}
.hero h1{font-size:clamp(24px,5.4vw,36px);font-weight:900;color:var(--teal-d)}
.hero p.tag{margin-top:8px;font-size:clamp(13.5px,3.2vw,16px);color:#3d565e}
.badges{margin-top:12px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap}
.badges span{background:#e5f4f2;border:1px solid #bfe0dc;border-radius:999px;padding:4px 14px;font-size:12.5px;font-weight:700;color:var(--teal-d)}
.wrap{max-width:760px;margin:0 auto;padding:14px 18px 60px}
h2.sec{font-size:19px;color:var(--teal-d);border-left:6px solid var(--teal-l);padding-left:12px;margin:30px 0 12px}
.card{background:#fff;border:1px solid #e2ecea;border-radius:14px;padding:18px 20px;margin-bottom:14px}
.gate{background:#fff;border:2px solid var(--teal-l);border-radius:18px;padding:24px 20px;text-align:center;box-shadow:0 12px 34px rgba(10,90,84,.12)}
.x-btn{display:inline-block;background:#0f1419;color:#fff;font-weight:900;font-size:16px;text-decoration:none;border-radius:999px;padding:13px 34px}
.why{font-size:12.5px;color:#4b5f66;background:#eef7f6;border-radius:10px;padding:11px 13px;margin-top:14px;text-align:left}
.follow{display:flex;gap:14px;align-items:flex-start;text-align:left;background:#fff;border:1px solid #dce9e7;border-radius:14px;padding:15px;margin-top:16px}
.follow img{width:56px;height:56px;border-radius:50%;border:3px solid var(--teal-l);object-fit:cover;flex:none}
.follow b{color:var(--teal-d);font-size:14px}
.follow p{font-size:12px;color:#4b5f66;margin:5px 0 9px;line-height:1.7}
.follow .fbtn{display:inline-block;background:#0f1419;color:#fff;font-weight:800;font-size:12px;text-decoration:none;border-radius:999px;padding:8px 18px}
form.post label{display:block;font-weight:800;font-size:13px;color:var(--teal-d);margin:12px 0 4px}
form.post input[type=text],form.post input[type=url],form.post input[type=email],form.post textarea{width:100%;border:1.5px solid #cfe2df;border-radius:10px;padding:10px 12px;font-size:14px;font-family:inherit}
form.post textarea{min-height:120px;resize:vertical}
form.post .hint{font-size:11.5px;color:#5b6f76;margin-top:3px}
form.post button{margin-top:16px;background:linear-gradient(135deg,var(--teal-l),var(--teal-d));color:#fff;font-weight:900;font-size:16px;border:none;border-radius:999px;padding:13px 40px;cursor:pointer}
.err{background:#fdecea;border:1px solid #f5b9b2;color:#a33;border-radius:10px;padding:10px 14px;font-size:13.5px;margin-bottom:12px}
.okm{background:#e8f7ee;border:1px solid #a9dcc0;color:#116b3c;border-radius:10px;padding:10px 14px;font-size:13.5px;margin-bottom:12px}
.post-item{background:#fff;border:1px solid #e2ecea;border-radius:14px;padding:16px 18px;margin-bottom:12px}
.post-item .t{font-weight:900;font-size:16px;color:#16323c}
.post-item .meta{font-size:11.5px;color:#6b7f86;margin-top:2px}
.post-item .meta a{color:var(--teal);font-weight:700;text-decoration:none}
.post-item .b{font-size:14px;margin-top:8px;white-space:pre-wrap;word-break:break-word}
.post-item .u a{display:inline-block;margin-top:10px;font-size:13px;font-weight:800;color:var(--teal);word-break:break-all}
.pager{text-align:center;margin-top:18px;font-size:14px}
.pager a,.pager b{display:inline-block;margin:0 4px;padding:6px 12px;border-radius:8px;background:#fff;border:1px solid #d5e6e3;text-decoration:none;color:var(--teal-d)}
.pager b{background:var(--teal-d);color:#fff;border-color:var(--teal-d)}
footer{background:var(--teal-d);color:#cfe8e5;text-align:center;font-size:13px;padding:20px 14px;margin-top:40px}
footer a{color:#fff}
</style>
</head>
<body>
<header>
  <a href="kbbs.php">Kurage BBS</a>
  <?php if ($KBBS_X && $logged_in && $user !== ''): ?><span class="u">@<?php echo h($user); ?> ／ <a href="?logout=1" style="font-weight:400">ログアウト</a></span><?php endif; ?>
</header>

<div class="hero">
  <img src="images/kurage-ecosystem-avatar.png" alt="Kurage" style="height:120px;display:block;margin:0 auto 8px;filter:drop-shadow(0 6px 16px rgba(10,90,84,.25))">
  <h1>宣伝OK・リンクOKの掲示板</h1>
  <p class="tag">会社・お店・サービスのPRを歓迎します。ホームページへのリンクもどうぞ。</p>
  <div class="badges"><span>宣伝歓迎</span><span>リンクOK</span><span>掲載無料</span><span>𝕏ログイン制</span></div>
</div>

<div class="wrap">

<div class="card" style="font-size:13.5px">
  <b style="color:var(--teal-d)">この掲示板について</b><br>
  「宣伝お断り」の掲示板は多いですが、ここは逆です。<b>あなたの会社・お店・サービスを自由にPRしてください</b>（ホームページへのリンク掲載OK）。
  なりすまし・スパム防止のため、投稿には<b>𝕏ログイン</b>と、<b>宣伝するURLと同じドメインのメールアドレス</b>（＝その会社のご本人様）が必要です。
</div>

<h2 class="sec">📝 宣伝を投稿する</h2>
<?php if ($err): ?><div class="err"><?php echo h($err); ?></div><?php endif; ?>
<?php if ($ok_msg): ?><div class="okm"><?php echo h($ok_msg); ?></div><?php endif; ?>

<?php if ($logged_in): ?>
<div class="card">
  <form class="post" method="post" action="kbbs.php">
    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
    <label>タイトル（60文字まで）</label>
    <input type="text" name="title" maxlength="60" required value="<?php echo h($_POST['title'] ?? ''); ?>" placeholder="例）名古屋の◯◯サロン、初回50%オフやってます">
    <label>本文（1,200文字まで）</label>
    <textarea name="text" maxlength="1200" required placeholder="サービスの紹介、こだわり、お知らせなど、ご自由にどうぞ。"><?php echo h($_POST['text'] ?? ''); ?></textarea>
    <label>宣伝するホームページのURL</label>
    <input type="url" name="url" required value="<?php echo h($_POST['url'] ?? ''); ?>" placeholder="https://example.jp/">
    <div class="hint">投稿に表示され、リンクになります。</div>
    <label>メールアドレス（URLと同じドメイン）</label>
    <input type="email" name="email" required value="<?php echo h($_POST['email'] ?? ''); ?>" placeholder="info@example.jp">
    <div class="hint">ご本人様確認のため、<b>宣伝するURLと同じドメイン</b>のメールアドレスが必要です（例：URLが example.jp なら 〇〇@example.jp）。メールアドレスは公開されません。</div>
    <button type="submit" onclick="if(window.gtag)gtag('event','kbbs_post_submit')">投稿する（無料）</button>
  </form>
</div>
<?php if ($KBBS_X): ?>
<div class="follow">
  <img src="xb_bittensor-icon.jpg" alt="@xb_bittensor" loading="lazy">
  <div><b>𝕏 @xb_bittensor をフォローしてください</b>
    <p>掲示板を運営する、Kurage（名古屋のAIシステム開発会社エクスブリッジ）の開発・運営者です。フォローいただくとお知らせが届きます。<b>ご投稿を拝見して、@xb_bittensor からフォロー・DMでご連絡やご提案をさせていただく場合があります。</b></p>
    <a class="fbtn" href="https://x.com/intent/follow?screen_name=xb_bittensor" target="_blank" rel="noopener" onclick="if(window.gtag)gtag('event','follow_click',{source:'kbbs'})">𝕏 @xb_bittensor をフォロー</a>
  </div>
</div>
<?php endif; ?>
<?php else: ?>
<div class="gate">
  <p style="font-weight:800;color:var(--teal-d);font-size:16px">投稿には 𝕏 ログインが必要です</p>
  <p style="font-size:13.5px;margin:8px 0 16px">閲覧はどなたでも自由。投稿は𝕏アカウントをお持ちの方に限定しています（なりすまし・スパム対策）。</p>
  <a class="x-btn" href="?login=1" onclick="if(window.gtag)gtag('event','kbbs_x_login_click')">𝕏 でログインして投稿する</a>
  <div class="why">
    <b>投稿にあたって</b><br>
    ・宣伝するURLと<b>同じドメインのメールアドレス</b>のご入力が必要です（ご本人様確認のため。メールは公開されません）。<br>
    ・運営（𝕏 <b>@xb_bittensor</b>）から、フォローやDMでご連絡・ご提案をさせていただく場合があります。<br>
    ・公序良俗に反する投稿・虚偽の投稿・無関係なドメインでの投稿は削除します。
  </div>
  <div class="follow">
    <img src="xb_bittensor-icon.jpg" alt="@xb_bittensor" loading="lazy">
    <div><b>𝕏 @xb_bittensor をフォローしてください</b>
      <p>掲示板を運営する、Kurage（名古屋のAIシステム開発会社エクスブリッジ）の開発・運営者です。フォローいただくとお知らせが届きます。<b>ご投稿を拝見して、@xb_bittensor からフォロー・DMでご連絡やご提案をさせていただく場合があります。</b></p>
      <a class="fbtn" href="https://x.com/intent/follow?screen_name=xb_bittensor" target="_blank" rel="noopener" onclick="if(window.gtag)gtag('event','follow_click',{source:'kbbs'})">𝕏 @xb_bittensor をフォロー</a>
    </div>
  </div>
</div>
<?php endif; ?>

<h2 class="sec">📋 みんなの宣伝（<?php echo (int)$total; ?>件）</h2>
<?php if (!$posts): ?>
<div class="card" style="text-align:center;color:#5b6f76">まだ投稿がありません。最初の宣伝、お待ちしています！</div>
<?php endif; ?>
<?php foreach ($posts as $p): ?>
<div class="post-item">
  <div class="t"><?php echo h($p['title']); ?></div>
  <div class="meta"><?php echo h(date('Y/m/d H:i', (int)$p['ts'])); ?> ／ 投稿:
    <?php if (!empty($p['user'])): ?><a href="https://x.com/<?php echo rawurlencode($p['user']); ?>" target="_blank" rel="noopener">@<?php echo h($p['user']); ?></a>
    <?php else: ?><?php echo h($p['host'] ?? parse_url($p['url'], PHP_URL_HOST)); ?><?php endif; ?></div>
  <div class="b"><?php echo h($p['text']); ?></div>
  <div class="u"><a href="<?php echo h($p['url']); ?>" target="_blank">🔗 <?php echo h($p['url']); ?></a></div>
</div>
<?php endforeach; ?>
<?php if ($pages > 1): ?>
<div class="pager"><?php for ($i = 1; $i <= $pages; $i++): ?>
  <?php if ($i === $page): ?><b><?php echo $i; ?></b><?php else: ?><a href="kbbs.php?p=<?php echo $i; ?>"><?php echo $i; ?></a><?php endif; ?>
<?php endfor; ?></div>
<?php endif; ?>

<?php if ($KBBS_X): ?>
<h2 class="sec">🔗 なぜ、宣伝OKの掲示板なのか</h2>
<div class="card" style="font-size:14px">
  <p><b style="color:var(--teal-d)">被リンクは、いまも検索順位の土台です。</b><br>
  Googleは「他のサイトからリンクされているサイト＝誰かに推薦されているサイト」を信頼します。
  どれだけ良いホームページを作っても、外部からのリンクがゼロのままでは、検索エンジンにとって「誰にも言及されていないお店」と同じ。
  <b>外部サイトからの被リンクは、SEOの最重要シグナルのひとつ</b>であり続けています。</p>
  <p style="margin-top:12px"><b style="color:var(--teal-d)">でも、URLを書ける場所が減りました。</b><br>
  かつてのインターネットには、気軽に自社サイトを紹介できる掲示板やリンク集がたくさんあり、
  小さな会社でも自然に被リンクを育てられました。いまは「宣伝お断り」が当たり前になり、
  URLを書き込める場所は驚くほど少なくなっています。<b>「被リンクが大事」と言われるのに、リンクを張れる場所がない</b>——
  この矛盾を少しでも解消したくて、この掲示板を開設しました。</p>
  <p style="margin-top:12px">だからここでは、堂々と宣伝してください。あなたの投稿はそのまま<b>あなたのサイトへの被リンク</b>になります。
  同一ドメインのメール確認があるので、スパムに埋もれることもありません。</p>
</div>

<div class="card" style="border:2px solid var(--teal-l);box-shadow:0 10px 28px rgba(10,90,84,.10)">
  <b style="color:var(--teal-d);font-size:15.5px">🏢 経営者の皆さまへ — あなたのサイトにも、この掲示板を置きませんか</b>
  <p style="font-size:13.5px;margin-top:8px">
  こういう「荒れない宣伝板」が、いろいろなサイトに増えるほど、被リンクを張り合える健全な場所がネットに戻ってきます。
  kbbsは<b>1ファイルのPHPをFTPで置くだけ</b>で、あなたのサイトにも同じ掲示板を設置できます（DB不要・改変自由のMITライセンス）。
  さらに管理画面には、投稿した会社のURLと本人確認済みメールが一覧されるので、<b>設置するだけで見込み客との接点リストが育ちます</b>。</p>
  <p style="margin-top:12px"><a href="https://kappstore.exbridge.jp/app.php?id=4bd9a6f3f99cdc05"
     style="display:inline-block;background:linear-gradient(135deg,var(--teal-l),var(--teal-d));color:#fff;font-weight:900;font-size:14.5px;text-decoration:none;border-radius:999px;padding:11px 26px"
     onclick="if(window.gtag)gtag('event','product_click',{item:'kbbs-product',source:location.pathname})">🛍️ Kurage App Store で見る（買い切り55,000円・ソース同梱）</a></p>
</div>

<div class="card" style="margin-top:26px;background:#eef7f6;border-color:#cfe6e2;font-size:13px">
  <b>運営:</b> 株式会社エクスブリッジ（名古屋のAIシステム開発会社）。
  この掲示板は1ファイルPHP・DB不要の自社開発です。
  AI活用の情報交換は<a href="https://kurage.exbridge.jp/nagoya-ai-study.php?ref=kbbs" style="color:var(--teal);font-weight:700">名古屋AI経営勉強会</a>、
  業務システムのご相談は<a href="https://kurage.exbridge.jp/chat.php?ref=kbbs" style="color:var(--teal);font-weight:700">無料AI相談</a>へ。
</div>
<?php endif; ?>

</div>

<footer>Kurage BBS（kbbs） — <a href="https://exbridge.jp/">株式会社エクスブリッジ</a> ・ <a href="https://kurage.exbridge.jp/">Kurage</a></footer>
</body>
</html>
