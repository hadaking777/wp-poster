<?php
/**
 * まとめて投稿する例（コマンドから動かす）
 *   php post_cli.php items.json
 * items.json は次の形の配列：
 *   [{"key":"商品ID","title":"...","content":"...","categories":["レビュー"],"tags":["新作"],"image_url":""}]
 */
require __DIR__ . '/includes/lib.php';
$conf = wpp_config();

$file = $argv[1] ?? '';
if ($file === '' || !file_exists($file)) { fwrite(STDERR, "使い方: php post_cli.php items.json\n"); exit(1); }
$items = json_decode(file_get_contents($file), true);
if (!is_array($items)) { fwrite(STDERR, "JSONを読めませんでした\n"); exit(1); }

$chk = wpp_check($conf);
if (!$chk['ok']) { fwrite(STDERR, "接続できません: {$chk['error']}\n"); exit(1); }
echo "接続OK（{$chk['name']}）。{$file} の " . count($items) . " 件を処理します\n\n";

$ok = 0; $skip = 0; $ng = 0;
foreach ($items as $i => $item) {
    $t = mb_substr($item['title'] ?? '', 0, 30);
    $r = wpp_post_article($conf, $item);
    if (!$r['ok'])                 { $ng++;   echo "[NG] {$t} … {$r['error']}\n"; }
    elseif (!empty($r['skipped'])) { $skip++; echo "[飛] {$t}（投稿済み）\n"; }
    else                           { $ok++;   echo "[OK] {$t} → ID{$r['post_id']}\n"; }
    sleep(max(1, (int)($conf['wait_seconds'] ?? 2)));
}
echo "\n完了：成功{$ok}件 / 飛ばし{$skip}件 / 失敗{$ng}件\n";
