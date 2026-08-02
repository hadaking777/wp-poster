<?php
/**
 * WordPress投稿ツール ── 画面
 * 生成した文章を貼って、WordPressへ下書き投稿する。
 */
session_start();
require __DIR__ . '/includes/lib.php';

$conf = wpp_config();

if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }

$login_error = '';
if (($_POST['action'] ?? '') === 'login') {
    if (password_verify($_POST['password'] ?? '', $conf['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['wpp'] = true;
        header('Location: index.php'); exit;
    }
    $login_error = 'パスワードが違います';
}
$in = !empty($_SESSION['wpp']);

$notice = ''; $ng = false; $result = null; $check = null;

$posting = $in && in_array($_POST['action'] ?? '', array('save_conf','change_password','post'), true);
if ($posting && !wpp_token_ok()) {
    $notice = '画面を開き直してから、もう一度お試しください'; $ng = true;
    $_POST['action'] = '';
}

if ($in && ($_POST['action'] ?? '') === 'save_conf') {
    $conf['wp_url']         = trim($_POST['wp_url'] ?? '');
    $conf['wp_user']        = trim($_POST['wp_user'] ?? '');
    $ap = trim($_POST['wp_app_pass'] ?? '');
    if ($ap !== '') { $conf['wp_app_pass'] = $ap; }
    $conf['default_status'] = ($_POST['default_status'] ?? 'draft') === 'publish' ? 'publish' : 'draft';
    wpp_save_config($conf);
    $notice = '接続先を保存しました';
}

if ($in && ($_POST['action'] ?? '') === 'change_password') {
    $new = $_POST['new_password'] ?? '';
    if (!password_verify($_POST['current_password'] ?? '', $conf['password_hash'])) { $notice = '現在のパスワードが違います'; $ng = true; }
    elseif (mb_strlen($new) < 6) { $notice = '6文字以上にしてください'; $ng = true; }
    else { $conf['password_hash'] = password_hash($new, PASSWORD_DEFAULT); wpp_save_config($conf); $notice = 'パスワードを変更しました'; }
}

if ($in && ($_GET['check'] ?? '') === '1') { $check = wpp_check($conf); }

if ($in && ($_POST['action'] ?? '') === 'post') {
    $item = array(
        'key'        => trim($_POST['key'] ?? ''),
        'title'      => trim($_POST['title'] ?? ''),
        'content'    => (string)($_POST['content'] ?? ''),
        'status'     => ($_POST['status'] ?? 'draft') === 'publish' ? 'publish' : 'draft',
        'slug'       => trim($_POST['slug'] ?? ''),
        'image_url'  => trim($_POST['image_url'] ?? ''),
        'categories' => array_filter(array_map('trim', explode(',', $_POST['categories'] ?? ''))),
        'tags'       => array_filter(array_map('trim', explode(',', $_POST['tags'] ?? ''))),
    );
    if ($item['title'] === '') { $notice = 'タイトルが空です'; $ng = true; }
    else { $result = wpp_post_article($conf, $item); }
}

if ($in && ($_GET['clear_done'] ?? '') === '1') {
    @unlink(WPP_DONE); $notice = '投稿済みの記録を消しました';
}

$done = $in ? wpp_done() : array();
$view = $_GET['view'] ?? '';
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WordPress投稿ツール</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Zen+Kaku+Gothic+New:wght@400;700;900&family=JetBrains+Mono:wght@400;700&display=swap');
:root{--ink:#12233b;--paper:#f2efe9;--accent:#e8590c;--accent2:#0ca678;--sub:#67788c}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Zen Kaku Gothic New',sans-serif;background:var(--paper);color:var(--ink);line-height:1.8;
 background-image:linear-gradient(rgba(18,35,59,.05) 1px,transparent 1px);background-size:100% 30px;padding-bottom:70px}
header{background:var(--ink);color:var(--paper);padding:18px 26px;display:flex;gap:16px;align-items:baseline;
 flex-wrap:wrap;border-bottom:5px solid var(--accent)}
header h1{font-size:20px;font-weight:900;letter-spacing:.05em}
header .sub{font-size:11px;color:#8fa6bd;font-family:'JetBrains Mono',monospace}
header nav{margin-left:auto;display:flex;gap:14px;font-size:13px}
header nav a{color:#8fa6bd;text-decoration:none}
header nav a:hover{color:var(--accent2)}
.wrap{max-width:960px;margin:0 auto;padding:24px 18px}
.card{background:#fff;border:2px solid var(--ink);box-shadow:6px 6px 0 var(--ink);padding:22px;margin-bottom:22px}
.card h2{font-size:15px;font-weight:900;letter-spacing:.06em;display:flex;align-items:center;gap:8px}
.card h2::before{content:'';width:12px;height:12px;background:var(--accent);transform:rotate(45deg)}
.card .lead{font-size:11px;color:var(--sub);font-family:'JetBrains Mono',monospace;letter-spacing:.12em;margin-bottom:16px}
label{display:block;font-size:13px;font-weight:700;margin:14px 0 5px}
label .hint{font-weight:400;color:var(--sub);font-size:11px;margin-left:6px}
input[type=text],input[type=password],textarea,select{width:100%;padding:10px 12px;border:2px solid var(--ink);
 background:#fff;font-family:inherit;font-size:14px}
textarea{min-height:200px;resize:vertical;line-height:1.7;font-size:13px}
input:focus,textarea:focus,select:focus{outline:none;border-color:var(--accent);box-shadow:3px 3px 0 var(--accent)}
.btn{display:inline-block;padding:11px 24px;border:2px solid var(--ink);background:var(--accent);color:#fff;
 font-weight:900;font-size:14px;cursor:pointer;font-family:inherit;box-shadow:4px 4px 0 var(--ink);text-decoration:none}
.btn:hover{transform:translate(2px,2px);box-shadow:2px 2px 0 var(--ink)}
.btn.ghost{background:#fff;color:var(--ink)}
.btn.mini{padding:6px 13px;font-size:12px;box-shadow:3px 3px 0 var(--ink)}
.note{border:2px solid var(--ink);background:var(--accent2);color:#04241b;padding:12px 16px;font-weight:700;
 margin-bottom:18px;box-shadow:5px 5px 0 var(--ink)}
.note.ng{background:#e03131;color:#fff}
.row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:640px){.row{grid-template-columns:1fr}}
.mono{font-family:'JetBrains Mono',monospace;font-size:12px;word-break:break-all}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:10px}
th,td{border:1px solid var(--ink);padding:7px 9px;text-align:left}
th{background:#e5e0d6}
.login{max-width:390px;margin:70px auto}
ol.step{counter-reset:s;list-style:none}
ol.step li{counter-increment:s;position:relative;padding-left:32px;margin:9px 0;font-size:13px}
ol.step li::before{content:counter(s);position:absolute;left:0;top:1px;width:22px;height:22px;background:var(--ink);
 color:var(--paper);font-family:'JetBrains Mono',monospace;font-size:12px;display:flex;align-items:center;justify-content:center}
.small{font-size:12px;color:var(--sub)}
</style>
</head>
<body>

<?php if (!$in): ?>
  <header><h1>WP POSTER</h1><span class="sub">生成した記事をWordPressへ</span></header>
  <div class="wrap login">
    <div class="card">
      <h2>ログイン</h2><p class="lead">SIGN IN</p>
      <?php if ($login_error): ?><div class="note ng"><?= wpp_e($login_error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="login">
        <label>パスワード</label><input type="password" name="password" autofocus>
        <div style="margin-top:16px"><button class="btn" type="submit">入る</button></div>
      </form>
    </div>
  </div>
<?php else: ?>

<header>
  <h1>WP POSTER</h1><span class="sub">生成した記事をWordPressへ下書き投稿</span>
  <nav>
    <a href="index.php">投稿する</a>
    <a href="index.php?view=setting">接続先</a>
    <a href="index.php?view=done">投稿済み</a>
    <a href="index.php?logout=1">ログアウト</a>
  </nav>
</header>

<div class="wrap">
  <?php if ($notice): ?><div class="note <?= $ng ? 'ng' : '' ?>"><?= wpp_e($notice) ?></div><?php endif; ?>

  <?php if ($check): ?>
    <div class="note <?= $check['ok'] ? '' : 'ng' ?>">
      <?= $check['ok'] ? '接続OK：「' . wpp_e($check['name']) . '」として投稿できます' : '接続NG：' . wpp_e($check['error']) ?>
    </div>
  <?php endif; ?>

  <?php if ($result): ?>
    <div class="note <?= $result['ok'] ? '' : 'ng' ?>">
      <?php if (!$result['ok']): ?>
        投稿できませんでした：<?= wpp_e($result['error']) ?>
      <?php elseif (!empty($result['skipped'])): ?>
        <?= wpp_e($result['message']) ?>（投稿ID <?= (int)$result['post_id'] ?>）
      <?php else: ?>
        投稿しました。投稿ID <?= (int)$result['post_id'] ?>
        <?php if (!empty($result['link'])): ?>／<a href="<?= wpp_e($result['link']) ?>" target="_blank">記事を見る</a><?php endif; ?>
        <?php if (!empty($result['image'])): ?>／<?= wpp_e($result['image']) ?><?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($view === 'setting'): ?>
    <div class="card">
      <h2>接続先の設定</h2><p class="lead">CONNECTION</p>
      <form method="post">
        <input type="hidden" name="action" value="save_conf"><?= wpp_token_field() ?>
        <label>サイトのURL <span class="hint">https://example.com の形</span></label>
        <input type="text" name="wp_url" value="<?= wpp_e($conf['wp_url']) ?>" placeholder="https://example.com">
        <div class="row">
          <div><label>ユーザー名</label><input type="text" name="wp_user" value="<?= wpp_e($conf['wp_user']) ?>"></div>
          <div><label>アプリケーションパスワード <span class="hint"><?= $conf['wp_app_pass'] ? '設定済み' : '未設定' ?></span></label>
               <input type="password" name="wp_app_pass" placeholder="<?= $conf['wp_app_pass'] ? '変更する時だけ入力' : 'xxxx xxxx xxxx xxxx' ?>"></div>
        </div>
        <label>既定の公開状態</label>
        <select name="default_status">
          <option value="draft" <?= $conf['default_status']==='draft'?'selected':'' ?>>下書き（おすすめ）</option>
          <option value="publish" <?= $conf['default_status']==='publish'?'selected':'' ?>>すぐ公開</option>
        </select>
        <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
          <button class="btn" type="submit">保存する</button>
          <a class="btn ghost mini" href="index.php?check=1">接続を試す</a>
        </div>
      </form>
    </div>

    <div class="card">
      <h2>アプリケーションパスワードの作り方</h2><p class="lead">HOW TO</p>
      <ol class="step">
        <li>WordPressの管理画面にログインする</li>
        <li>「ユーザー」→ 自分のプロフィールを開く</li>
        <li>下の方の「アプリケーションパスワード」で名前を入れて発行する</li>
        <li>表示された文字列をコピーして、上の欄に貼る（空白ごとで大丈夫）</li>
      </ol>
      <p class="small">これは外部ツール専用のパスワードです。ログイン用とは別物で、管理画面には入れません。不要になったら管理画面からいつでも取り消せます。</p>
    </div>

    <div class="card" style="max-width:460px">
      <h2>このツールのパスワード変更</h2><p class="lead">PASSWORD</p>
      <form method="post">
        <input type="hidden" name="action" value="change_password"><?= wpp_token_field() ?>
        <label>現在のパスワード</label><input type="password" name="current_password">
        <label>新しいパスワード <span class="hint">6文字以上</span></label><input type="password" name="new_password">
        <div style="margin-top:16px"><button class="btn" type="submit">変更する</button></div>
      </form>
    </div>

  <?php elseif ($view === 'done'): ?>
    <div class="card">
      <h2>投稿済みの記録</h2><p class="lead">POSTED</p>
      <p class="small">ここに記録がある識別子は、もう一度送っても飛ばされます。二重投稿を防ぐための仕組みです。</p>
      <?php if (!$done): ?><p style="margin-top:12px">まだありません。</p><?php else: ?>
        <table>
          <tr><th>識別子</th><th>タイトル</th><th>投稿ID</th><th>日時</th></tr>
          <?php foreach (array_reverse($done, true) as $k => $v): ?>
            <tr>
              <td class="mono"><?= wpp_e($k) ?></td>
              <td><?= wpp_e($v['title'] ?? '') ?></td>
              <td><?= (int)($v['post_id'] ?? 0) ?></td>
              <td class="mono"><?= wpp_e(substr($v['at'] ?? '', 0, 16)) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <div style="margin-top:14px">
          <a class="btn ghost mini" href="index.php?clear_done=1" onclick="return confirm('記録を消すと、同じ記事をもう一度投稿できるようになります。よろしいですか？')">記録を消す</a>
        </div>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <?php if (trim($conf['wp_url']) === '' || trim($conf['wp_user']) === ''): ?>
      <div class="note ng">先に「接続先」を設定してください。</div>
    <?php endif; ?>

    <div class="card">
      <h2>記事を投稿する</h2><p class="lead">POST</p>
      <form method="post">
        <input type="hidden" name="action" value="post"><?= wpp_token_field() ?>

        <label>タイトル</label>
        <input type="text" name="title" value="<?= wpp_e($_POST['title'] ?? '') ?>">

        <label>本文 <span class="hint">HTMLのまま貼れます</span></label>
        <textarea name="content"><?= wpp_e($_POST['content'] ?? '') ?></textarea>

        <div class="row">
          <div><label>カテゴリ <span class="hint">カンマ区切り。無ければ自動で作成</span></label>
               <input type="text" name="categories" value="<?= wpp_e($_POST['categories'] ?? '') ?>" placeholder="レビュー, 新作"></div>
          <div><label>タグ <span class="hint">カンマ区切り</span></label>
               <input type="text" name="tags" value="<?= wpp_e($_POST['tags'] ?? '') ?>"></div>
        </div>

        <div class="row">
          <div><label>重複防止の識別子 <span class="hint">商品IDなど。同じ値は二度投稿しません</span></label>
               <input type="text" name="key" value="<?= wpp_e($_POST['key'] ?? '') ?>" placeholder="例：商品コード"></div>
          <div><label>URLの文字列 <span class="hint">空欄可。英数字推奨</span></label>
               <input type="text" name="slug" value="<?= wpp_e($_POST['slug'] ?? '') ?>"></div>
        </div>

        <label>アイキャッチ画像のURL <span class="hint">空欄可。指定すると取り込んで設定します</span></label>
        <input type="text" name="image_url" value="<?= wpp_e($_POST['image_url'] ?? '') ?>" placeholder="https://.../sample.jpg">

        <label>公開状態</label>
        <select name="status">
          <option value="draft">下書き（おすすめ）</option>
          <option value="publish">すぐ公開</option>
        </select>

        <div style="margin-top:20px"><button class="btn" type="submit">WordPressへ送る</button></div>
      </form>
    </div>

    <div class="card">
      <h2>つまずいた時に見るところ</h2><p class="lead">TROUBLE</p>
      <table>
        <tr><th>症状</th><th>原因として多いもの</th></tr>
        <tr><td>401が出る</td><td>ユーザー名かアプリケーションパスワードの間違い</td></tr>
        <tr><td>403が出る</td><td>セキュリティプラグインが弾いている。本文にscriptタグやハイフンの連続があると起きやすい</td></tr>
        <tr><td>404が出る</td><td>URLの間違い、またはREST APIが無効</td></tr>
        <tr><td>投稿はできるが画像が付かない</td><td>画像URLに直接アクセスできない、または容量制限</td></tr>
        <tr><td>同じ記事が2本できた</td><td>識別子を入れずに2回送った</td></tr>
      </table>
      <p class="small" style="margin-top:12px">切り分けの近道は、本文を「テスト」の2文字だけにして送ってみることです。それが通れば本文の中身が原因、それも弾かれるなら接続側が原因です。</p>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>
</body>
</html>
