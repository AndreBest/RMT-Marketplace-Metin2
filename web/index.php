<?php
$db = @new mysqli('mysql', 'root', 'futcurvepemetin2blackgames', 'player');
$db_ok = !$db->connect_error;
$tables_ok = $db_ok && $db->query("SHOW TABLES LIKE 'marketplace_items'")->num_rows > 0;

$current_player = trim($_GET['player'] ?? '');
$current_pid = 0;
$current_aid = 0;
$balance = 0.00;

if ($current_player && $db_ok) {
    $safe = $db->real_escape_string($current_player);
    $prow = $db->query("SELECT id, account_id FROM player WHERE name='{$safe}' LIMIT 1");
    if ($prow && $p = $prow->fetch_assoc()) {
        $current_pid = (int)$p['id'];
        $current_aid = (int)$p['account_id'];
        if ($tables_ok) {
            $brow = $db->query("SELECT balance FROM marketplace_balance WHERE account_id={$current_aid}");
            if ($brow && $b = $brow->fetch_assoc()) $balance = (float)$b['balance'];
        }
    }
}

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tables_ok) {
    $action = $_POST['action'] ?? '';

    if ($action === 'list' && isset($_POST['item_id'], $_POST['price'])) {
        $id = (int)$_POST['item_id'];
        $price = max(0.50, (float)$_POST['price']);
        $db->query("UPDATE marketplace_items SET price={$price}, status='listed' WHERE id={$id} AND status='inventory' AND account_id={$current_aid}");
        if ($db->affected_rows) $flash = 'Listed!';
    }

    if ($action === 'delist' && isset($_POST['item_id'])) {
        $id = (int)$_POST['item_id'];
        $db->query("UPDATE marketplace_items SET status='inventory', price=NULL WHERE id={$id} AND status='listed' AND account_id={$current_aid}");
        if ($db->affected_rows) $flash = 'Delisted.';
    }

    if ($action === 'withdraw' && isset($_POST['item_id'])) {
        $id = (int)$_POST['item_id'];
        $db->begin_transaction();
        try {
            $mi = $db->query("SELECT * FROM marketplace_items WHERE id={$id} AND status='inventory' AND account_id={$current_aid} FOR UPDATE")->fetch_assoc();
            if (!$mi) throw new Exception('Item not found');
            $login = $db->query("SELECT login FROM account.account WHERE id={$current_aid}")->fetch_assoc();
            if (!$login) throw new Exception('Account not found');
            $lg = $db->real_escape_string($login['login']);

            $db->query("INSERT INTO item_award (login,vnum,count,socket0,socket1,socket2,attrtype0,attrvalue0,attrtype1,attrvalue1,attrtype2,attrvalue2,attrtype3,attrvalue3,attrtype4,attrvalue4,attrtype5,attrvalue5,attrtype6,attrvalue6,mall,why,given_time) VALUES ('{$lg}',{$mi['item_vnum']},{$mi['item_count']},{$mi['socket0']},{$mi['socket1']},{$mi['socket2']},{$mi['attrtype0']},{$mi['attrvalue0']},{$mi['attrtype1']},{$mi['attrvalue1']},{$mi['attrtype2']},{$mi['attrvalue2']},{$mi['attrtype3']},{$mi['attrvalue3']},{$mi['attrtype4']},{$mi['attrvalue4']},{$mi['attrtype5']},{$mi['attrvalue5']},{$mi['attrtype6']},{$mi['attrvalue6']},1,'[GIFT] Marketplace',NOW())");
            $db->query("UPDATE marketplace_items SET status='withdrawn' WHERE id={$id}");
            $db->commit();
            $flash = 'Withdrawn! Check your in-game mail.';
        } catch (Exception $e) {
            $db->rollback();
            $flash = 'Error: '.$e->getMessage();
        }
    }

    if ($action === 'purchase' && isset($_POST['item_id']) && $current_aid > 0) {
        $id = (int)$_POST['item_id'];
        $db->begin_transaction();
        try {
            $item = $db->query("SELECT * FROM marketplace_items WHERE id={$id} AND status='listed' FOR UPDATE")->fetch_assoc();
            if (!$item) throw new Exception('Item no longer available');
            $price = (float)$item['price'];
            $seller_aid = (int)$item['account_id'];
            if ($seller_aid === $current_aid) throw new Exception('Cannot buy your own item');
            $brow = $db->query("SELECT balance FROM marketplace_balance WHERE account_id={$current_aid} FOR UPDATE")->fetch_assoc();
            if (!$brow || (float)$brow['balance'] < $price) throw new Exception('Insufficient balance');
            $db->query("UPDATE marketplace_balance SET balance=balance-{$price} WHERE account_id={$current_aid}");
            $db->query("INSERT INTO marketplace_balance (account_id,balance,total_sales) VALUES ({$seller_aid},{$price},1) ON DUPLICATE KEY UPDATE balance=balance+{$price}, total_sales=total_sales+1");
            $db->query("UPDATE marketplace_items SET status='sold', buyer_id={$current_aid}, sold_at=NOW() WHERE id={$id}");
            $db->query("INSERT INTO marketplace_transactions (item_id,seller_id,buyer_id,price) VALUES ({$id},{$seller_aid},{$current_aid},{$price})");
            $ne = $db->real_escape_string($item['item_name']);
            $buyer_login = $db->query("SELECT login FROM account.account WHERE id={$current_aid}")->fetch_assoc();
            if ($buyer_login) {
                $bl = $db->real_escape_string($buyer_login['login']);
                $db->query("INSERT INTO item_award (login,vnum,count,socket0,socket1,socket2,attrtype0,attrvalue0,attrtype1,attrvalue1,attrtype2,attrvalue2,attrtype3,attrvalue3,attrtype4,attrvalue4,attrtype5,attrvalue5,attrtype6,attrvalue6,mall,why,given_time) VALUES ('{$bl}',{$item['item_vnum']},{$item['item_count']},{$item['socket0']},{$item['socket1']},{$item['socket2']},{$item['attrtype0']},{$item['attrvalue0']},{$item['attrtype1']},{$item['attrvalue1']},{$item['attrtype2']},{$item['attrvalue2']},{$item['attrtype3']},{$item['attrvalue3']},{$item['attrtype4']},{$item['attrvalue4']},{$item['attrtype5']},{$item['attrvalue5']},{$item['attrtype6']},{$item['attrvalue6']},1,'[GIFT] Marketplace',NOW())");
            }
            $db->query("INSERT INTO marketplace_notifications (account_id,message) VALUES ({$seller_aid},'Your \"{$ne}\" sold for EUR {$price}!')");
            $db->query("INSERT INTO marketplace_notifications (account_id,message) VALUES ({$current_aid},'You purchased \"{$ne}\" for EUR {$price}. Check your in-game mail.')");
            $db->commit();
            $flash = 'Bought! Check your in-game mail.';
            $balance -= $price;
        } catch (Exception $e) {
            $db->rollback();
            $flash = 'Error: '.$e->getMessage();
        }
    }

    if ($flash && !headers_sent()) {
        $qs = ['flash' => $flash];
        if ($current_player) $qs['player'] = $current_player;
        header('Location: ?'.http_build_query($qs).'#inventory'); exit;
    }
}
$flash = $_GET['flash'] ?? '';

$ITEM_NAMES = [];
$_nf = __DIR__.'/item_names_en.txt';
if (file_exists($_nf))
    foreach (file($_nf, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $_ln)
        if (preg_match('/^(\d+)\t(.+)$/', $_ln, $_m)) $ITEM_NAMES[(int)$_m[1]] = trim($_m[2]);

$ITEM_ICONS = [];
$_if = __DIR__.'/item_list.txt';
if (file_exists($_if))
    foreach (file($_if, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $_ln)
        if (preg_match('/^(\d+)\t(.+)$/', $_ln, $_m)) $ITEM_ICONS[(int)$_m[1]] = trim($_m[2]);

function itemIcon($vnum) {
    global $ITEM_ICONS;
    return 'icons/'.($ITEM_ICONS[$vnum] ?? sprintf('%05d.png', $vnum));
}

function timeAgo($dt) {
    $d = time() - strtotime($dt);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d/60).'m ago';
    if ($d < 86400) return floor($d/3600).'h ago';
    return floor($d/86400).'d ago';
}

function stoneName($v) {
    global $ITEM_NAMES;
    if ($v <= 1) return null;
    if (isset($ITEM_NAMES[$v])) return $ITEM_NAMES[$v];
    if ($v > 100) return 'Item #'.$v;
    return null;
}

$ATTR_NAMES = [
    1=>'MAX HP',2=>'MAX SP',3=>'STR',4=>'DEX',5=>'VIT',6=>'INT',
    7=>'ATK Speed',8=>'MOV Speed',9=>'Cast Speed',10=>'HP Regen',11=>'SP Regen',
    12=>'Poison',13=>'Stun',14=>'Slow',15=>'Critical Hit',16=>'Penetrate',
    17=>'ATK Bonus',18=>'DEF Bonus',19=>'Strong vs Monster',20=>'Strong vs Metin',
    21=>'Strong vs Boss',22=>'Reflect',23=>'Block',24=>'Dodge',
    25=>'Poison Resist',26=>'Stun Resist',27=>'Slow Resist',
    28=>'Critical Resist',29=>'Penetrate Resist',
    53=>'Strong vs Half-Human',54=>'Resist Half-Human',71=>'Skill DMG',72=>'Normal DMG',
];
$PCT = [7,8,9,12,13,14,15,16,19,20,21,22,23,24,25,26,27,28,29,53,54,71,72];

function formatRow($row) {
    global $ATTR_NAMES, $PCT;
    $n = strtolower($row['item_name']);
    $type = match(true) {
        str_contains($n,'sword'),str_contains($n,'blade'),str_contains($n,'dagger'),
        str_contains($n,'bow'),str_contains($n,'bell'),str_contains($n,'fan') => 'Weapon',
        str_contains($n,'plate'),str_contains($n,'armor'),str_contains($n,'robe') => 'Armor',
        str_contains($n,'bracelet') => 'Bracelet', str_contains($n,'shield') => 'Shield',
        str_contains($n,'shoes'),str_contains($n,'boots') => 'Shoes',
        str_contains($n,'necklace') => 'Necklace', str_contains($n,'earring') => 'Earring',
        str_contains($n,'helmet'),str_contains($n,'cap') => 'Helmet',
        str_contains($n,'stone') => 'Stone', default => 'Item'
    };
    $stones = [];
    foreach ([0,1,2] as $i) { $s = stoneName((int)$row["socket{$i}"]); if ($s) $stones[] = $s; }
    $attrs = [];
    for ($i=0;$i<7;$i++) {
        $t=(int)$row["attrtype{$i}"]; $v=(int)$row["attrvalue{$i}"];
        if ($t>0 && $v!=0) {
            $nm = $ATTR_NAMES[$t] ?? "Attr#{$t}";
            $sf = in_array($t,$PCT) ? '%' : '';
            $attrs[] = [$nm, "+{$v}{$sf}"];
        }
    }
    return [
        'id'=>(int)$row['id'], 'vnum'=>(int)$row['item_vnum'], 'name'=>$row['item_name'],
        'type'=>$type, 'owner'=>$row['owner_name'], 'oid'=>(int)$row['owner_id'],
        'aid'=>(int)$row['account_id'], 'price'=>$row['price'], 'status'=>$row['status'],
        'promoted'=>!empty($row['promoted_until']) && strtotime($row['promoted_until'])>time(),
        'time'=>timeAgo($row['created_at']), 'stones'=>$stones, 'attrs'=>$attrs,
    ];
}

function rarity($name) {
    if (preg_match('/\+(\d)/',$name,$m)) {
        $lv=(int)$m[1]; if($lv>=9) return 'epic'; if($lv>=7) return 'rare'; if($lv>=4) return 'uncommon';
    }
    return 'common';
}

function stars($r) {
    $f=(int)$r; $h=($r-$f>=.3)?1:0; $e=5-$f-$h;
    return str_repeat('&#9733;',$f).($h?'&#9734;':'').str_repeat('&#9734;',$e);
}

$listed = []; $promoted = []; $myInv = []; $myListed = []; $notifications = [];
$seller_reviews = []; $seller_stats = ['name'=>'','sales'=>0,'rating'=>0.0];

if ($tables_ok) {
    $res = $db->query("SELECT * FROM marketplace_items WHERE status='listed' ORDER BY created_at DESC LIMIT 50");
    while ($res && $r=$res->fetch_assoc()) { $it=formatRow($r); $listed[]=$it; if($it['promoted']) $promoted[]=$it; }
    if ($current_player) {
        $safe = $db->real_escape_string($current_player);
        $res = $db->query("SELECT * FROM marketplace_items WHERE owner_name='{$safe}' AND status='inventory' ORDER BY created_at DESC");
        while ($res && $r=$res->fetch_assoc()) $myInv[] = formatRow($r);
        $res = $db->query("SELECT * FROM marketplace_items WHERE owner_name='{$safe}' AND status='listed' ORDER BY created_at DESC");
        while ($res && $r=$res->fetch_assoc()) $myListed[] = formatRow($r);
    }
    if ($current_aid > 0) {
        $res = $db->query("SELECT * FROM marketplace_notifications WHERE account_id={$current_aid} ORDER BY created_at DESC LIMIT 10");
        while ($res && $r=$res->fetch_assoc()) $notifications[] = $r;
    }
}
$unread = count(array_filter($notifications, fn($n) => !(int)($n['is_read'] ?? 0)));

$detail_id = (int)($_GET['detail'] ?? 0);
$detail_item = null;
if ($detail_id && $tables_ok) {
    $dres = $db->query("SELECT * FROM marketplace_items WHERE id={$detail_id}");
    if ($dres && $dr=$dres->fetch_assoc()) $detail_item = formatRow($dr);
}
if (!$detail_item && !empty($listed)) $detail_item = $listed[0];
if ($detail_item && $tables_ok) {
    $said = $detail_item['aid'];
    $rres = $db->query("SELECT * FROM marketplace_reviews WHERE seller_id={$said} ORDER BY created_at DESC LIMIT 5");
    while ($rres && $rv=$rres->fetch_assoc()) $seller_reviews[] = $rv;
    $sres = $db->query("SELECT total_sales FROM marketplace_balance WHERE account_id={$said}");
    if ($sres && $s=$sres->fetch_assoc()) $seller_stats['sales'] = (int)$s['total_sales'];
    $avgr = $db->query("SELECT AVG(rating) as r FROM marketplace_reviews WHERE seller_id={$said}");
    if ($avgr && $a=$avgr->fetch_assoc()) $seller_stats['rating'] = round((float)($a['r']??0),1);
    $seller_stats['name'] = $detail_item['owner'];
}

$base = '?'.($current_player ? 'player='.urlencode($current_player).'&' : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Market</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0e0e0e;color:#bbb;font:14px/1.5 system-ui,-apple-system,sans-serif}
a{color:red;text-decoration:none}a:hover{color:#f44}

#hd{background:#141414;border-bottom:1px solid #222;padding:0 20px;display:flex;align-items:center;height:48px;position:sticky;top:0;z-index:50}
#hd .logo{color:red;font-weight:700;font-size:15px;margin-right:28px}
#hd .tabs{display:flex;gap:1px}
#hd .tabs a{padding:5px 12px;color:#555;font-size:13px;border-radius:0;border-bottom:2px solid transparent;cursor:pointer}
#hd .tabs a:hover{color:#ccc;text-decoration:none}
#hd .tabs a.on{color:#fff;border-bottom-color:red}
#hd .right{margin-left:auto;display:flex;align-items:center;gap:12px}
#hd .bal{color:red;font-weight:600;font-size:13px}
#hd .uname{color:#fff;font-size:13px}

.bell-w{position:relative;cursor:pointer}
.bell-w .dot{position:absolute;top:-2px;right:-4px;background:red;width:7px;height:7px;border-radius:50%}
#ndd{display:none;position:absolute;right:0;top:30px;width:280px;background:#161616;border:1px solid #252525;border-radius:4px;z-index:99;overflow:hidden}
#ndd.show{display:block}
#ndd .nh{padding:8px 12px;font-size:11px;color:#fff;border-bottom:1px solid #1e1e1e;text-transform:uppercase;letter-spacing:.4px}
#ndd .ni{padding:9px 12px;font-size:12px;color:#fff;border-bottom:1px solid #1a1a1a;line-height:1.4}
#ndd .ni:last-child{border:none}
#ndd .ni.new{border-left:2px solid red}
#ndd .nt{color:#444;font-size:10px;margin-top:2px}

.main{max-width:1040px;margin:0 auto;padding:18px 16px 50px}

.flash{padding:9px 14px;border-radius:3px;margin-bottom:14px;font-size:13px;background:#000;color:#fff;border:1px solid red}

.warn-box{background:#1a1212;border:1px solid #331a1a;border-radius:4px;padding:14px;margin-bottom:18px;color:#c55;font-size:13px}
.warn-box b{display:block;margin-bottom:3px}

.page{display:none}.page.on{display:block}

h2.sec{font-size:11px;font-weight:600;color:#444;margin:20px 0 10px;text-transform:uppercase;letter-spacing:.6px}
.items{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:12px}

.itm{background:#141414;border:1px solid #1e1e1e;border-radius:4px;padding:14px;display:flex;flex-direction:column}
.itm:hover{border-color:#2a2a2a}
.itm .row1{display:flex;gap:10px;margin-bottom:8px}
.itm .iico{height:32px;width:auto;flex-shrink:0}
.itm .iinfo{flex:1;min-width:0}
.itm .iname{font-weight:600;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.itm .iname{color:#fff}
.itm .isub{font-size:11px;color:#3a3a3a}
.itm .iprice{color:red;font-weight:700;font-size:15px;white-space:nowrap;margin-left:auto;flex-shrink:0}
.itm .stones{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:5px}
.itm .stones span{font-size:10px;color:#cc2;background:rgba(204,204,34,.06);padding:1px 5px;border-radius:2px}
.itm .bonuses{list-style:none;font-size:12px;color:#666;margin-bottom:8px}
.itm .bonuses li{padding:1px 0}
.itm .bonuses li::before{content:"\25B8 ";color:red;font-size:10px}
.itm .meta{font-size:11px;color:#333;display:flex;justify-content:space-between;margin-top:auto;padding-top:6px}
.itm .acts{display:flex;gap:5px;margin-top:10px}

.btn{display:inline-block;padding:5px 12px;border-radius:3px;font-size:12px;font-weight:500;cursor:pointer;border:1px solid transparent;text-align:center;text-decoration:none;line-height:1.4}
.btn:hover{text-decoration:none}
.btn-red{background:red;color:#fff;border-color:red}.btn-red:hover{background:#d00}
.btn-dim{background:transparent;color:#666;border-color:#252525}.btn-dim:hover{border-color:#444;color:#aaa}
.btn-wht{background:#ddd;color:#111;border-color:#ddd}.btn-wht:hover{background:#ccc}
.btn-full{width:100%;display:block;text-align:center}
.btn-s{padding:4px 10px;font-size:11px}

.filters{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.filters input[type=text],.filters select{background:#141414;border:1px solid #222;color:#aaa;padding:6px 10px;border-radius:3px;font-size:13px;outline:none}
.filters input[type=text]{min-width:170px;flex:1}
.filters input[type=text]:focus{border-color:#444}
.filters label{font-size:11px;color:#555;display:flex;align-items:center;gap:4px;cursor:pointer}
.filters label input{accent-color:red}

.det-wrap{display:grid;grid-template-columns:1fr 260px;gap:16px}
.det-box{background:#141414;border:1px solid #1e1e1e;border-radius:4px;padding:18px}
.det-ico{height:48px;width:auto;margin-bottom:10px}
.det-name{font-size:18px;font-weight:700;color:#fff}
.det-price{font-size:22px;font-weight:700;color:red;margin:12px 0}
.stats td{padding:4px 0;font-size:13px;border-bottom:1px solid #1a1a1a}
.stats td:first-child{color:#555;padding-right:16px}
.stats td:last-child{color:#8b8;text-align:right}
.sbox{background:#141414;border:1px solid #1e1e1e;border-radius:4px;padding:14px;margin-bottom:10px}
.sbox h4{font-size:14px;color:#ccc;margin-bottom:4px}
.sbox .sm{font-size:12px;color:#555;line-height:1.7}
.sbox .rt{color:red}
.rev{padding:8px 0;border-bottom:1px solid #1a1a1a;font-size:12px}.rev:last-child{border:none}
.rev .rh{display:flex;justify-content:space-between;margin-bottom:2px}
.rev .rs{color:red;font-size:11px}

.charge-wrap{max-width:400px}
.fg{margin-bottom:12px}
.fg label{display:block;font-size:11px;color:#555;margin-bottom:3px}
.fg input{width:100%;background:#141414;border:1px solid #222;color:#aaa;padding:7px 10px;border-radius:3px;font-size:13px;outline:none}
.fg input:focus{border-color:#444}
.pm-row{display:flex;gap:8px;margin-bottom:16px}
.pm{flex:1;padding:12px 8px;text-align:center;border:1px solid #222;border-radius:3px;cursor:pointer;background:#141414}
.pm:hover,.pm.sel{border-color:red}
.pm .pi{font-size:20px;margin-bottom:3px}.pm .pn{font-size:11px;color:#555}

.code-tabs{display:flex;gap:1px;margin-bottom:12px}
.code-tabs button{background:none;border:none;color:#444;padding:5px 10px;cursor:pointer;font-size:11px;font-weight:600;border-bottom:2px solid transparent}
.code-tabs button:hover{color:#888}
.code-tabs button.on{color:red;border-bottom-color:red}
.cpanel{display:none}.cpanel.on{display:block}
.cblock{background:#000;border:1px solid red;border-radius:3px;padding:14px;font:12px/1.5 'Consolas','Courier New',monospace;color:#fff;overflow-x:auto;white-space:pre;margin-bottom:12px}
.clabel{display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 7px;border-radius:2px;margin-bottom:6px;letter-spacing:.4px}
.clabel.sql{background:#111828;color:#68a}.clabel.lua{background:#112818;color:#6a8}.clabel.php{background:#1a1128;color:#86a}

.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:100;align-items:center;justify-content:center}
.overlay.show{display:flex}
.modal{background:#161616;border:1px solid #252525;border-radius:5px;padding:22px;max-width:360px;width:90%}
.modal h3{font-size:15px;margin-bottom:12px;color:#ddd}
.modal .mf{display:flex;gap:6px;margin-top:14px;justify-content:flex-end}

.empty-msg{text-align:center;color:#333;padding:36px 20px;font-size:13px}
.req-box{background:#000;border:1px solid red;border-radius:4px;padding:14px;margin-bottom:16px;font-size:12px;color:#fff;line-height:1.6}
.req-box b{color:red}
.arch{background:#0a0a0a;border:1px solid #1a1a1a;border-radius:3px;padding:18px;font:12px/1.7 monospace;color:#666;margin-bottom:16px;text-align:center}

@media(max-width:700px){.det-wrap{grid-template-columns:1fr}#hd .tabs{display:none}.filters{flex-direction:column}}
</style>
</head>
<body>

<div id="hd">
  <span class="logo">Market</span>
  <div class="tabs">
    <a class="on" data-p="home">Shop</a>
    <a data-p="inventory">Your Items</a>
    <a data-p="browse">Search</a>
    <a data-p="charge">Top Up</a>
    <a data-p="code">Docs</a>
  </div>
  <div class="right">
    <div class="bell-w" onclick="document.getElementById('ndd').classList.toggle('show')">
      &#x1f514;<?php if($unread):?><span class="dot"></span><?php endif?>
      <div id="ndd">
        <div class="nh">Notifications</div>
        <?php if(empty($notifications)):?>
          <div class="ni" style="color:#555"><?=$current_player?'Nothing yet.':'Set ?player='?></div>
        <?php else: foreach($notifications as $n):?>
          <div class="ni <?=!(int)$n['is_read']?'new':''?>"><?=htmlspecialchars($n['message'])?><div class="nt"><?=timeAgo($n['created_at'])?></div></div>
        <?php endforeach; endif?>
      </div>
    </div>
    <?php if($current_player):?>
      <span class="uname"><?=htmlspecialchars($current_player)?></span>
      <span class="bal">&euro;<?=number_format($balance,2)?></span>
    <?php else:?>
      <span class="uname" style="color:red">?player=Name</span>
    <?php endif?>
  </div>
</div>

<div class="main">
<?php if($flash):?><div class="flash"><?=htmlspecialchars($flash)?></div><?php endif?>
<?php if(!$db_ok):?><div class="warn-box"><b>DB connection failed</b><?=htmlspecialchars($db->connect_error)?></div>
<?php elseif(!$tables_ok):?><div class="warn-box"><b>Tables missing</b>Run the SQL from the Docs tab.</div><?php endif?>

<div class="page on" id="p-home">
  <h2 class="sec">Recent listings</h2>
  <?php if(empty($listed)):?>
    <p class="empty-msg">Nothing listed yet.</p>
  <?php else:?>
  <div class="items">
    <?php foreach($listed as $it):?>
    <div class="itm">
      <div class="row1">
        <img class="iico" src="<?=itemIcon($it['vnum'])?>" alt="">
        <div class="iinfo"><div class="iname"><?=htmlspecialchars($it['name'])?></div><div class="isub"><?=$it['type']?></div></div>
        <div class="iprice">&euro;<?=number_format((float)$it['price'],2)?></div>
      </div>
      <?php if($it['stones']):?><div class="stones"><?php foreach($it['stones'] as $s):?><span><?=htmlspecialchars($s)?></span><?php endforeach?></div><?php endif?>
      <?php if($it['attrs']):?><ul class="bonuses"><?php foreach($it['attrs'] as $a):?><li><?=$a[0]?> <?=$a[1]?></li><?php endforeach?></ul><?php endif?>
      <div class="meta"><span><?=htmlspecialchars($it['owner'])?></span><span><?=$it['time']?></span></div>
      <a href="<?=$base?>detail=<?=$it['id']?>" class="btn btn-red btn-full" style="margin-top:10px">View</a>
    </div>
    <?php endforeach?>
  </div>
  <?php endif?>
</div>

<div class="page" id="p-inventory">
  <?php if(!$current_player):?>
    <p class="empty-msg">Set ?player=YourCharName in the URL.</p>
  <?php else:?>

  <h2 class="sec">In inventory</h2>
  <?php if(empty($myInv)):?>
    <p class="empty-msg">No items. Drag something onto the General Store NPC in-game.</p>
  <?php else:?>
  <div class="items">
    <?php foreach($myInv as $it):?>
    <div class="itm">
      <div class="row1">
        <img class="iico" src="<?=itemIcon($it['vnum'])?>" alt="">
        <div class="iinfo"><div class="iname"><?=htmlspecialchars($it['name'])?></div><div class="isub"><?=$it['type']?></div></div>
      </div>
      <?php if($it['stones']):?><div class="stones"><?php foreach($it['stones'] as $s):?><span><?=htmlspecialchars($s)?></span><?php endforeach?></div><?php endif?>
      <?php if($it['attrs']):?><ul class="bonuses"><?php foreach($it['attrs'] as $a):?><li><?=$a[0]?> <?=$a[1]?></li><?php endforeach?></ul><?php endif?>
      <div class="meta"><span>Added <?=$it['time']?></span></div>
      <div class="acts">
        <form method="POST" style="flex:1"><input type="hidden" name="action" value="withdraw"><input type="hidden" name="item_id" value="<?=$it['id']?>">
          <button type="submit" class="btn btn-dim btn-full btn-s">Withdraw</button></form>
        <button class="btn btn-red btn-s" style="flex:1" onclick="openSell(<?=$it['id']?>,'<?=htmlspecialchars($it['name'],ENT_QUOTES)?>')">Set price</button>
      </div>
    </div>
    <?php endforeach?>
  </div>
  <?php endif?>

  <h2 class="sec">Currently listed</h2>
  <?php if(empty($myListed)):?>
    <p class="empty-msg">No active listings.</p>
  <?php else:?>
  <div class="items">
    <?php foreach($myListed as $it):?>
    <div class="itm">
      <div class="row1">
        <img class="iico" src="<?=itemIcon($it['vnum'])?>" alt="">
        <div class="iinfo"><div class="iname"><?=htmlspecialchars($it['name'])?></div><div class="isub"><?=$it['type']?></div></div>
        <div class="iprice">&euro;<?=number_format((float)$it['price'],2)?></div>
      </div>
      <?php if($it['stones']):?><div class="stones"><?php foreach($it['stones'] as $s):?><span><?=htmlspecialchars($s)?></span><?php endforeach?></div><?php endif?>
      <?php if($it['attrs']):?><ul class="bonuses"><?php foreach($it['attrs'] as $a):?><li><?=$a[0]?> <?=$a[1]?></li><?php endforeach?></ul><?php endif?>
      <div class="meta"><span>Listed <?=$it['time']?></span></div>
      <form method="POST" style="margin-top:10px"><input type="hidden" name="action" value="delist"><input type="hidden" name="item_id" value="<?=$it['id']?>">
        <button type="submit" class="btn btn-dim btn-full btn-s">Delist</button></form>
    </div>
    <?php endforeach?>
  </div>
  <?php endif?>

  <?php endif?>
</div>

<div class="page" id="p-browse">
  <div class="filters">
    <input type="text" id="q" placeholder="Search..." oninput="doFilter()">
    <select id="sort" onchange="doFilter()"><option value="">Sort</option><option value="asc">Price low-high</option><option value="desc">Price high-low</option></select>
    <label><input type="checkbox" id="fS" onchange="doFilter()"> Has stones</label>
    <label><input type="checkbox" id="fB" onchange="doFilter()"> Has bonuses</label>
  </div>
  <?php if(empty($listed)):?>
    <p class="empty-msg">Nothing for sale.</p>
  <?php else:?>
  <div class="items" id="bGrid">
    <?php foreach($listed as $it):?>
    <div class="itm fi" data-n="<?=strtolower($it['name'])?>" data-p="<?=$it['price']?>" data-s="<?=count($it['stones'])?>" data-a="<?=count($it['attrs'])?>">
      <div class="row1">
        <img class="iico" src="<?=itemIcon($it['vnum'])?>" alt="">
        <div class="iinfo"><div class="iname"><?=htmlspecialchars($it['name'])?></div><div class="isub"><?=$it['type']?></div></div>
        <div class="iprice">&euro;<?=number_format((float)$it['price'],2)?></div>
      </div>
      <?php if($it['stones']):?><div class="stones"><?php foreach($it['stones'] as $s):?><span><?=htmlspecialchars($s)?></span><?php endforeach?></div><?php endif?>
      <?php if($it['attrs']):?><ul class="bonuses"><?php foreach($it['attrs'] as $a):?><li><?=$a[0]?> <?=$a[1]?></li><?php endforeach?></ul><?php endif?>
      <div class="meta"><span><?=htmlspecialchars($it['owner'])?></span><span><?=$it['time']?></span></div>
      <a href="<?=$base?>detail=<?=$it['id']?>" class="btn btn-red btn-full" style="margin-top:10px">Buy</a>
    </div>
    <?php endforeach?>
  </div>
  <p id="nores" style="display:none" class="empty-msg">No results.</p>
  <?php endif?>
</div>

<div class="page" id="p-detail">
  <?php if(!$detail_item):?>
    <p class="empty-msg">Click an item to see details.</p>
  <?php else: $di=$detail_item; $ds=$seller_stats; ?>
  <a href="javascript:history.back()" style="font-size:11px;color:#fff;margin-bottom:12px;display:inline-block">&lt; back</a>
  <div class="det-wrap">
    <div>
      <div class="det-box">
        <img class="det-ico" src="<?=itemIcon($di['vnum'])?>" alt="">
        <div style="font-size:10px;color:#444;margin-bottom:4px"><?=$di['type']?> &middot; #<?=$di['vnum']?></div>
        <div class="det-name"><?=htmlspecialchars($di['name'])?></div>
        <div class="det-price">&euro;<?=number_format((float)$di['price'],2)?></div>
        <?php if($di['stones']):?>
        <div class="stones" style="margin-bottom:12px"><?php foreach($di['stones'] as $s):?><span><?=htmlspecialchars($s)?></span><?php endforeach?></div>
        <?php endif?>
        <?php if($di['attrs']):?>
        <div style="font-size:10px;color:#444;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px">Bonuses</div>
        <table class="stats" style="width:100%;border-collapse:collapse;margin-bottom:12px">
          <?php foreach($di['attrs'] as $a):?><tr><td><?=$a[0]?></td><td><?=$a[1]?></td></tr><?php endforeach?>
        </table>
        <?php endif?>
        <?php if($di['status']==='listed' && $di['aid']!==$current_aid):?>
        <form method="POST"><input type="hidden" name="action" value="purchase"><input type="hidden" name="item_id" value="<?=$di['id']?>">
          <button type="submit" class="btn btn-red btn-full" onclick="return confirm('Buy for EUR <?=number_format((float)$di['price'],2)?>?')">Buy now</button></form>
        <?php elseif($di['aid']===$current_aid):?>
          <div style="text-align:center;color:#333;font-size:11px;padding:6px">This is yours</div>
        <?php endif?>
      </div>
    </div>
    <div>
      <div class="sbox"><h4><?=htmlspecialchars($ds['name'])?></h4>
        <div class="sm">Sales: <b style="color:#bbb"><?=$ds['sales']?></b><br>Rating: <span class="rt"><?=stars($ds['rating'])?></span> <?=$ds['rating']?></div></div>
      <div class="sbox"><h4 style="font-size:12px;color:#555">Reviews</h4>
        <?php if(empty($seller_reviews)):?><div style="color:#333;font-size:11px">None yet.</div>
        <?php else: foreach($seller_reviews as $rev):?>
          <div class="rev"><div class="rh"><b><?=htmlspecialchars($rev['buyer_name'])?></b><span class="rs"><?=stars((float)$rev['rating'])?></span></div><div style="color:#555;font-size:11px"><?=htmlspecialchars($rev['comment'])?></div></div>
        <?php endforeach; endif?>
      </div>
    </div>
  </div>
  <?php endif?>
</div>

<div class="page" id="p-charge">
  <h2 class="sec">Add funds</h2>
  <p style="color:#555;font-size:12px;margin-bottom:14px">Balance: <b style="color:red">&euro;<?=number_format($balance,2)?></b></p>
  <div class="charge-wrap">
    <div class="fg"><label>Amount (&euro;)</label><input type="number" min="5" step="1" value="50"></div>
    <div style="font-size:11px;color:#444;margin-bottom:5px">Payment method</div>
    <div class="pm-row">
      <div class="pm sel" onclick="selPM(this)"><div class="pi">&#128179;</div><div class="pn">Stripe</div></div>
      <div class="pm" onclick="selPM(this)"><div class="pi">P</div><div class="pn">PayPal</div></div>
      <div class="pm" onclick="selPM(this)"><div class="pi">&#127974;</div><div class="pn">Bank</div></div>
    </div>
    <div class="fg"><label>Card number</label><input placeholder="4242 4242 4242 4242" maxlength="19"></div>
    <div style="display:flex;gap:8px"><div class="fg" style="flex:1"><label>Expiry</label><input placeholder="MM/YY" maxlength="5"></div><div class="fg" style="flex:1"><label>CVC</label><input placeholder="123" maxlength="4"></div></div>
    <button class="btn btn-wht btn-full" onclick="alert('Stripe/PayPal API integration goes here.')">Pay</button>
  </div>
</div>

<div class="page" id="p-code">
  <div class="req-box">
    <b>Requirements</b><br>
    1. <b>mysql_query</b> by Mijago<br>
    2. <b>Extended Item Award</b> by Vegas
  </div>

  <div class="code-tabs">
    <button class="on" onclick="ctab('sql',this)">SQL</button>
    <button onclick="ctab('lua',this)">Quest</button>
    <button onclick="ctab('php',this)">PHP</button>
    <button onclick="ctab('fix',this)">Fix</button>
  </div>

  <div class="cpanel on" id="cp-sql">
    <p style="font-size:11px;color:#fff;margin-bottom:8px">Run on <b>player</b> database.</p>
<div class="cblock">CREATE TABLE IF NOT EXISTS marketplace_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL, account_id INT NOT NULL,
    owner_name VARCHAR(24) NOT NULL,
    item_vnum INT NOT NULL, item_name VARCHAR(64) NOT NULL,
    item_count INT NOT NULL DEFAULT 1,
    socket0 BIGINT DEFAULT 0, socket1 BIGINT DEFAULT 0, socket2 BIGINT DEFAULT 0,
    attrtype0 TINYINT DEFAULT 0, attrvalue0 SMALLINT DEFAULT 0,
    attrtype1 TINYINT DEFAULT 0, attrvalue1 SMALLINT DEFAULT 0,
    attrtype2 TINYINT DEFAULT 0, attrvalue2 SMALLINT DEFAULT 0,
    attrtype3 TINYINT DEFAULT 0, attrvalue3 SMALLINT DEFAULT 0,
    attrtype4 TINYINT DEFAULT 0, attrvalue4 SMALLINT DEFAULT 0,
    attrtype5 TINYINT DEFAULT 0, attrvalue5 SMALLINT DEFAULT 0,
    attrtype6 TINYINT DEFAULT 0, attrvalue6 SMALLINT DEFAULT 0,
    price DECIMAL(10,2) DEFAULT NULL,
    status ENUM('inventory','listed','sold','withdrawn') NOT NULL DEFAULT 'inventory',
    buyer_id INT DEFAULT NULL, promoted_until DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sold_at DATETIME DEFAULT NULL,
    INDEX idx_owner (owner_name, status),
    INDEX idx_status (status, created_at),
    INDEX idx_account (account_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marketplace_balance (
    account_id INT PRIMARY KEY,
    balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_sales INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marketplace_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL, seller_id INT NOT NULL, buyer_id INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marketplace_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL, buyer_id INT NOT NULL,
    buyer_name VARCHAR(24) NOT NULL, rating TINYINT NOT NULL, comment TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_seller (seller_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marketplace_charges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL, amount DECIMAL(10,2) NOT NULL,
    method ENUM('stripe','paypal','bank_transfer') NOT NULL,
    status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    stripe_session_id VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marketplace_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL, message TEXT NOT NULL,
    is_read TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_account (account_id, is_read)
) ENGINE=InnoDB;</div>
  </div>

  <div class="cpanel" id="cp-lua">
    <p style="font-size:11px;color:#fff;margin-bottom:8px">marketplace.quest - NPC 9010 (General Store)</p>
<div class="cblock">quest marketplace begin
    state start begin

        when 9010.take begin
            local player_name = pc.get_name()
            local vnum = item.get_vnum()
            local name = item.get_name()
            local count = item.get_count()
            local s0 = item.get_socket(0)
            local s1 = item.get_socket(1)
            local s2 = item.get_socket(2)

            say_title("Web Marketplace")
            say("")
            say("Transfer this item to the web?")
            say("")
            say_item_vnum(vnum)
            if count > 1 then
                say("[Count] "..count)
            end
            say("")

            if select("Transfer to Web", "Cancel") ~= 1 then
                return
            end

            local p = mysql_query("SELECT id, account_id FROM player.player WHERE name='"..player_name.."' LIMIT 1")
            if not p or not p[1] then
                say_title("Error")
                say("Could not verify account.")
                return
            end

            local pid = p["id"][1]
            local aid = p["account_id"][1]
            local item_id = item.get_id()

            local at0 = 0
            local av0 = 0
            local at1 = 0
            local av1 = 0
            local at2 = 0
            local av2 = 0
            local at3 = 0
            local av3 = 0
            local at4 = 0
            local av4 = 0
            local at5 = 0
            local av5 = 0
            local at6 = 0
            local av6 = 0

            local it = mysql_query("SELECT attrtype0,attrvalue0,attrtype1,attrvalue1,attrtype2,attrvalue2,attrtype3,attrvalue3,attrtype4,attrvalue4,attrtype5,attrvalue5,attrtype6,attrvalue6 FROM player.item WHERE id="..item_id)

            if it and it[1] then
                at0 = it["attrtype0"][1]
                av0 = it["attrvalue0"][1]
                at1 = it["attrtype1"][1]
                av1 = it["attrvalue1"][1]
                at2 = it["attrtype2"][1]
                av2 = it["attrvalue2"][1]
                at3 = it["attrtype3"][1]
                av3 = it["attrvalue3"][1]
                at4 = it["attrtype4"][1]
                av4 = it["attrvalue4"][1]
                at5 = it["attrtype5"][1]
                av5 = it["attrvalue5"][1]
                at6 = it["attrtype6"][1]
                av6 = it["attrvalue6"][1]
            end

            mysql_query("INSERT INTO player.marketplace_items (owner_id,account_id,owner_name,item_vnum,item_name,item_count,socket0,socket1,socket2,attrtype0,attrvalue0,attrtype1,attrvalue1,attrtype2,attrvalue2,attrtype3,attrvalue3,attrtype4,attrvalue4,attrtype5,attrvalue5,attrtype6,attrvalue6,status,created_at) VALUES ("..pid..","..aid..",'"..player_name.."',"..vnum..",'"..name.."',"..count..","..s0..","..s1..","..s2..","..at0..","..av0..","..at1..","..av1..","..at2..","..av2..","..at3..","..av3..","..at4..","..av4..","..at5..","..av5..","..at6..","..av6..",'inventory',NOW())")

            item.remove()

            mysql_query("INSERT INTO player.marketplace_notifications (account_id,message,created_at) VALUES ("..aid..",'Item "..name.." added to web inventory.',NOW())")

            say_title("Web Marketplace")
            say("")
            say("Done! Check the website.")
        end

    end
end

</div>
  </div>

  <div class="cpanel" id="cp-php">
<div class="cblock">-- list for sale
UPDATE marketplace_items SET price=?, status='listed' WHERE id=? AND account_id=?;

-- delist
UPDATE marketplace_items SET status='inventory', price=NULL WHERE id=? AND account_id=?;

-- purchase (in transaction)
SELECT * FROM marketplace_items WHERE id=? AND status='listed' FOR UPDATE;
-- deduct buyer, credit seller, update status, create transaction + notification

-- withdraw via item_award
SELECT login FROM account.account WHERE id=?;
INSERT INTO item_award (login,vnum,count,socket0..2,attrtype0..6,attrvalue0..6,mall,why,given_time)
VALUES (?, ?, ?, ..., 1, '[GIFT] Marketplace', NOW());
UPDATE marketplace_items SET status='withdrawn' WHERE id=?;</div>
  </div>

  <div class="cpanel" id="cp-fix">
    <p style="font-size:11px;color:#fff;margin-bottom:8px">db source fix - ClientManager.cpp line ~971</p>
    <p style="font-size:11px;color:#fff;margin-bottom:8px">CheckItemSocket overwrites stone vnums with 1 (empty slot). Skip it when sockets already have values.</p>
<div class="cblock">if (pItemAward->dwSocket0 <= 1 && pItemAward->dwSocket1 <= 1 && pItemAward->dwSocket2 <= 1)
    ItemAwardManager::instance().CheckItemSocket(*pItemAward, *pItemTable);</div>
  </div>
</div>

</div>

<div class="overlay" id="sellModal">
<div class="modal">
  <h3>Set a price</h3>
  <form method="POST">
    <input type="hidden" name="action" value="list">
    <input type="hidden" name="item_id" id="sellId">
    <div style="font-size:12px;color:#666;margin-bottom:8px" id="sellName"></div>
    <div class="fg"><label>Price (&euro;)</label><input name="price" type="number" min="0.50" step="0.50" required></div>
    <div class="mf">
      <button type="button" class="btn btn-dim" onclick="closeModal('sellModal')">Cancel</button>
      <button type="submit" class="btn btn-red">List</button>
    </div>
  </form>
</div>
</div>

<script>
function go(id){
  document.querySelectorAll('.page').forEach(function(p){p.classList.remove('on')});
  var el=document.getElementById('p-'+id);if(el)el.classList.add('on');
  document.querySelectorAll('#hd .tabs a').forEach(function(a){a.classList.remove('on')});
  document.querySelectorAll('#hd .tabs a').forEach(function(a){if(a.dataset.p===id)a.classList.add('on')});
  scrollTo(0,0);if(id!=='detail')location.hash=id;
}
document.querySelectorAll('#hd .tabs a').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();go(this.dataset.p)})});
var h=location.hash.replace('#','');if(h)go(h);
<?php if($detail_id):?>go('detail');<?php endif?>
document.addEventListener('click',function(e){if(!e.target.closest('.bell-w'))document.getElementById('ndd').classList.remove('show')});
function openModal(id){document.getElementById(id).classList.add('show')}
function closeModal(id){document.getElementById(id).classList.remove('show')}
document.querySelectorAll('.overlay').forEach(function(m){m.addEventListener('click',function(e){if(e.target===m)m.classList.remove('show')})});
function openSell(id,name){document.getElementById('sellId').value=id;document.getElementById('sellName').textContent=name;openModal('sellModal')}
function selPM(el){document.querySelectorAll('.pm').forEach(function(p){p.classList.remove('sel')});el.classList.add('sel')}
function doFilter(){
  var q=document.getElementById('q').value.toLowerCase(),s=document.getElementById('sort').value,
      fs=document.getElementById('fS').checked,fb=document.getElementById('fB').checked,
      els=[].slice.call(document.querySelectorAll('.fi')),n=0;
  els.forEach(function(el){var ok=true;if(q&&el.dataset.n.indexOf(q)<0)ok=false;if(fs&&+el.dataset.s===0)ok=false;if(fb&&+el.dataset.a===0)ok=false;el.style.display=ok?'':'none';if(ok)n++});
  if(s){var g=document.getElementById('bGrid');if(g)els.filter(function(e){return e.style.display!=='none'}).sort(function(a,b){return s==='asc'?a.dataset.p-b.dataset.p:b.dataset.p-a.dataset.p}).forEach(function(e){g.appendChild(e)})}
  var nr=document.getElementById('nores');if(nr)nr.style.display=n?'none':'block';
}
function ctab(id,btn){document.querySelectorAll('.cpanel').forEach(function(p){p.classList.remove('on')});document.querySelectorAll('.code-tabs button').forEach(function(b){b.classList.remove('on')});document.getElementById('cp-'+id).classList.add('on');btn.classList.add('on')}
var fl=document.querySelector('.flash');if(fl)setTimeout(function(){fl.style.opacity='0';fl.style.transition='opacity .3s';setTimeout(function(){fl.remove()},300)},4000);
</script>
</body>
</html>
