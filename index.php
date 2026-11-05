<?php
/**
 * صفحه اصلی فروشگاه - طراحی کلاسیک
 */
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/coupons.php';

// صفحه خوش‌آمدگویی: یک‌بار در هر نشست، هنگام ورود به صفحه اصلی
if (session_status() === PHP_SESSION_NONE) session_start();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_SESSION['welcomed']) && !isset($_GET['skip']) && welcome_enabled($pdo)) {
    header('Location: welcome.php');
    exit;
}

// کدهای تخفیف فعال برای نمایش در Hero
$heroCoupons = [];
try {
    coupons_ensure($pdo);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM coupon_products WHERE coupon_id = ?");
    foreach ($pdo->query("SELECT * FROM coupons WHERE status = 1 ORDER BY ends_at ASC, id DESC LIMIT 12")->fetchAll() as $cp) {
        if (coupon_state($cp) !== 'active') continue;
        $cnt->execute([$cp['id']]); $cp['n_products'] = (int)$cnt->fetchColumn();
        $heroCoupons[] = $cp;
        if (count($heroCoupons) >= 4) break;
    }
} catch (Throwable $e) { $heroCoupons = []; }

// ============================================================
// توابع کمکی نمایش (فقط در صورت نبودن تعریف می‌شوند)
// ============================================================
if (!function_exists('ms_fa_digits')) {
    function ms_fa_digits($value) {
        return strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }
}
if (!function_exists('ms_money')) {
    function ms_money($n) {
        return ms_fa_digits(number_format((float)$n));
    }
}
if (!function_exists('ms_stars')) {
    function ms_stars($r) {
        $r = max(0, min(5, (int)round((float)$r)));
        return str_repeat('<span class="on">★</span>', $r) . str_repeat('<span>★</span>', 5 - $r);
    }
}
if (!function_exists('ms_product_card')) {
    function ms_product_card(array $p, bool $forceDiscount = false): void {
        $isAvailable = ($p['stock_status'] === 'in_stock' && $p['stock'] > 0 && $p['price'] > 0);
        $hasSale = ($p['sale_price'] && $p['sale_price'] > 0 && $p['sale_price'] < $p['price']);
        $rating = (float)($p['rating'] ?? 0);
        ?>
        <article class="pc tilt <?= !$isAvailable ? 'pc-off' : '' ?>">
            <?php if (!$isAvailable): ?>
                <span class="pc-tag pc-tag-off">ناموجود</span>
            <?php elseif ($hasSale): ?>
                <span class="pc-tag"><?= ms_fa_digits((int)$p['discount_percent']) ?>٪ تخفیف</span>
            <?php endif; ?>
            <a href="product.php?id=<?= (int)$p['id'] ?>" class="pc-img">
                <img src="<?= htmlspecialchars($p['main_image'] ?: 'assets/no-image.png') ?>"
                     alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy"
                     onerror="this.onerror=null;this.src='assets/no-image.png'">
            </a>
            <div class="pc-body">
                <h3 class="pc-title"><a href="product.php?id=<?= (int)$p['id'] ?>"><?= htmlspecialchars(mb_substr($p['name'], 0, 80)) ?></a></h3>
                <div class="pc-stars" aria-label="امتیاز">
                    <?php if ($rating > 0): ?><?= ms_stars($rating) ?><em>(<?= ms_fa_digits(number_format($rating, 1)) ?>)</em><?php else: ?><span class="pc-noreview">بدون امتیاز</span><?php endif; ?>
                </div>
                <div class="pc-foot">
                    <div class="pc-price">
                        <?php if ($p['price'] > 0): ?>
                            <?php if ($hasSale): ?>
                                <del><?= ms_money($p['price']) ?></del>
                                <b><?= ms_money($p['sale_price']) ?> <small>تومان</small></b>
                            <?php else: ?>
                                <del class="ghost">&nbsp;</del>
                                <b><?= ms_money($p['price']) ?> <small>تومان</small></b>
                            <?php endif; ?>
                        <?php else: ?>
                            <del class="ghost">&nbsp;</del><b class="pc-call">تماس بگیرید</b>
                        <?php endif; ?>
                    </div>
                    <?php if ($isAvailable): ?>
                        <button class="pc-add" onclick="addToCart(<?= (int)$p['id'] ?>, event)" title="افزودن به سبد خرید" aria-label="افزودن به سبد خرید">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h3l2.6 12.2a1 1 0 0 0 1 .8h9.2a1 1 0 0 0 1-.8L21 7H6"/><path d="M13 9v4M11 11h4"/></svg>
                        </button>
                    <?php else: ?>
                        <button class="pc-add" disabled aria-label="ناموجود">✕</button>
                    <?php endif; ?>
                </div>
            </div>
        </article>
        <?php
    }
}

// ============================================================
// ۱. دسته‌بندی‌ها
// ============================================================
$categories = $pdo->query("
    SELECT c.id, c.name, c.slug, COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id AND p.status = 1
    GROUP BY c.id
    HAVING product_count > 0
    ORDER BY product_count DESC
    LIMIT 12
")->fetchAll();

// ============================================================
// ۲. محصولات ویژه
// ============================================================
require_once 'includes/featured.php';
featured_ensure($pdo);
// اول محصولات منتخب مدیر؛ اگر مدیر چیزی انتخاب نکرده باشد، پرامتیازترین‌ها
$featuredProducts = $pdo->query("
    SELECT * FROM products
    WHERE status = 1 AND featured = 1
    ORDER BY id DESC
    LIMIT 12
")->fetchAll();
if (!$featuredProducts) $featuredProducts = $pdo->query("
    SELECT * FROM products
    WHERE status = 1 AND stock_status = 'in_stock' AND stock > 0 AND price > 0
    ORDER BY rating DESC, review_count DESC
    LIMIT 8
")->fetchAll();

// ============================================================
// ۳. جدیدترین محصولات
// ============================================================
$newestProducts = $pdo->query("
    SELECT * FROM products WHERE status = 1 ORDER BY id DESC LIMIT 8
")->fetchAll();

// ============================================================
// ۴. تخفیف‌دارها
// ============================================================
$discountProducts = $pdo->query("
    SELECT * FROM products
    WHERE status = 1 AND stock_status = 'in_stock'
      AND sale_price IS NOT NULL AND sale_price > 0 AND sale_price < price
    ORDER BY discount_percent DESC
    LIMIT 8
")->fetchAll();

// ============================================================
// ۵. آمار
// ============================================================
$totalProducts = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 1")->fetchColumn();
$totalInStock  = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 1 AND stock_status = 'in_stock'")->fetchColumn();
$cartCount     = array_sum($_SESSION['cart'] ?? []);

$catIcons = ['🔧','🔨','🪛','🪚','⚙️','📏','🧰','🦺','🔩','🪜','🔌','⛏️'];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(SITE_NAME) ?> | فروشگاه آنلاین ابزارآلات</title>
<meta name="description" content="خرید آنلاین ابزارآلات صنعتی، کارگاهی و خانگی از معتبرترین برندها با ضمانت اصالت و ارسال سریع">
<link rel="preconnect" href="https://cdn.jsdelivr.net">
<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
<style>
:root{
  --gold:#f59e0b; --gold-light:#fbbf24; --gold-deep:#d97706; --gold-pale:#fffbeb;
  --navy:#1e293b; --navy-deep:#0f172a;
  --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --cream:#f1f5f9; --paper:#fff;
  --red:#ef4444; --green:#059669;
}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:'Vazirmatn',tahoma,sans-serif;background:var(--cream);color:var(--ink);line-height:1.7;-webkit-font-smoothing:antialiased;overflow-x:hidden}
a{text-decoration:none;color:inherit}
img{max-width:100%;display:block}
button{font-family:inherit;cursor:pointer;border:none;outline:none}
ul{list-style:none}
::selection{background:var(--gold-light);color:var(--ink)}
.container{max-width:1280px;margin:0 auto;padding:0 16px}
main.ab{padding:24px 0 8px;display:flex;flex-direction:column;gap:40px}

/* هماهنگی هدر مشترک با تم کهربایی */
.sh2-search button,.sh2-cat>button,.sh2-badge{color:var(--ink)!important}
.sh2-mark{color:var(--ink)!important}

/* نوار اعلان بالای سایت */
.ab-top{background:var(--gold-light);color:var(--ink);font-size:12px;font-weight:800;padding:7px 16px}
.ab-top .in{max-width:1280px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px}
.ab-top .mid{font-weight:900;text-align:center;flex:1}
.ab-top .side{display:flex;gap:14px;white-space:nowrap}
.ab-top a:hover{text-decoration:underline}
@media(max-width:768px){.ab-top .side{display:none}}

/* ======= Hero ======= */
.hero{position:relative;overflow:hidden;border-radius:26px;border:1px solid #fde68a;box-shadow:0 18px 40px -22px rgba(217,119,6,.45);
  background:linear-gradient(110deg,var(--gold-pale),#fff 50%,#fef3c7);display:flex;align-items:center;gap:20px;padding:44px 48px}
.hero::before,.hero::after{content:'';position:absolute;width:320px;height:320px;border-radius:50%;filter:blur(60px);pointer-events:none}
.hero::before{top:-90px;left:-80px;background:rgba(252,211,77,.28)}
.hero::after{bottom:-100px;right:-80px;background:rgba(250,204,21,.22)}
.hero-text{position:relative;z-index:2;flex:1 1 58%;display:flex;flex-direction:column;gap:18px}
.hero-badge{align-self:flex-start;background:rgba(251,191,36,.22);border:1px solid rgba(245,158,11,.45);color:#78350f;font-size:12px;font-weight:900;padding:6px 16px;border-radius:999px}
.hero h1{font-size:44px;font-weight:900;line-height:1.45;color:var(--ink)}
.hero h1 span{color:var(--gold-deep);text-decoration:underline wavy var(--gold-light);text-underline-offset:10px;text-decoration-thickness:2px}
.hero p.sub{font-size:14px;color:#475569;max-width:560px}
.hero-btns{display:flex;flex-wrap:wrap;gap:12px;padding-top:4px}
.hb{padding:13px 30px;border-radius:16px;font-size:14px;font-weight:900;transition:transform .2s,box-shadow .2s}
.hb:hover{transform:translateY(-2px)}
.hb-1{background:linear-gradient(90deg,var(--gold-light),var(--gold));color:var(--ink);box-shadow:0 12px 22px -10px var(--gold)}
.hb-2{background:#fff;border:1px solid var(--line);color:#1e293b;box-shadow:0 2px 6px rgba(15,23,42,.06)}
.hero-stats{display:flex;gap:10px;flex-wrap:wrap}
.hero-stats span{background:#fff;border:1px solid #fde68a;border-radius:12px;padding:5px 14px;font-size:12px;font-weight:800;color:#78350f}
.hero-coupons .hc-title{font-size:12px;color:#92400e;font-weight:800;margin-bottom:8px}
.hc-list{display:flex;flex-wrap:wrap;gap:10px}
.hc{position:relative;overflow:hidden;min-width:160px;padding:10px 18px;border-radius:14px;color:#fff;text-align:center;border:2px dashed rgba(255,255,255,.7);
  box-shadow:0 10px 22px -10px rgba(0,0,0,.5);font-family:inherit;transition:transform .2s}
.hc:hover{transform:translateY(-3px) rotate(-.6deg)}
.hc::after{content:'';position:absolute;top:0;left:-60%;width:40%;height:100%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.35),transparent);transform:skewX(-20deg);animation:hcshine 3.6s infinite}
@keyframes hcshine{0%{left:-60%}60%,100%{left:130%}}
.hc .hc-val{display:block;font-size:17px;font-weight:800}
.hc .hc-code{display:inline-block;margin:5px 0 2px;background:rgba(255,255,255,.93);color:#222;padding:2px 12px;border-radius:8px;font-family:Consolas,monospace;font-size:15px;letter-spacing:2px;font-weight:800}
.hc small{display:block;font-size:11px;opacity:.95}
.hc-0{background:linear-gradient(135deg,#ff416c,#ff8a00)}
.hc-1{background:linear-gradient(135deg,#00b4db,#0083b0 55%,#6a11cb)}
.hc-2{background:linear-gradient(135deg,#11998e,#38ef7d)}
.hc-3{background:linear-gradient(135deg,#8e2de2,#f64f9b)}
.hc-ok{outline:3px solid #fff}

/* صحنه سه‌بعدی چرخ‌دنده */
.hero-visual{position:relative;z-index:2;flex:1 1 36%;min-height:360px;perspective:1000px;display:flex;align-items:center;justify-content:center}
.hero-visual::before{content:'';position:absolute;width:300px;height:300px;border-radius:50%;background:radial-gradient(circle,rgba(251,191,36,.45),transparent 68%)}
.h3d-tool{position:absolute;left:50%;top:50%;width:0;height:0;transform-style:preserve-3d;will-change:transform}
.h3d-gear{position:absolute;left:-110px;top:-110px;width:220px;height:220px;transform-style:preserve-3d;will-change:transform}
.h3d-gear .gl{position:absolute;inset:0;clip-path:var(--gear);background:#4b5568}
.h3d-gear .gl:nth-child(even){background:#434d60}
.h3d-gear .gl.gf{background:
  radial-gradient(circle,transparent 0 15%,#b45309 15% 17.5%,#fbbf24 17.5% 21%,transparent 21.5%),
  repeating-conic-gradient(from 0deg,#d5dbe5 0 8deg,#9aa5b8 8deg 16deg,#e9edf3 16deg 24deg,#8793a8 24deg 30deg)}
.h3d-gear .gl.gb{background:repeating-conic-gradient(from 0deg,#6c778b 0 10deg,#556075 10deg 20deg)}
.h3d-nutpos{position:absolute;left:0;top:0;width:0;height:0;transform-style:preserve-3d;will-change:transform}
.h3d-nut{position:absolute;left:0;top:0;width:0;height:0;transform-style:preserve-3d}
.h3d-nut .nf{position:absolute;left:-14px;top:-11px;width:28px;height:22px;background:linear-gradient(180deg,#fde68a,#f59e0b 55%,#b45309);backface-visibility:hidden}
.h3d-nut .nf:nth-child(odd){filter:brightness(.88)}
.h3d-nut .nf:nth-child(1){transform:rotateY(0deg) translateZ(24.25px)}
.h3d-nut .nf:nth-child(2){transform:rotateY(60deg) translateZ(24.25px)}
.h3d-nut .nf:nth-child(3){transform:rotateY(120deg) translateZ(24.25px)}
.h3d-nut .nf:nth-child(4){transform:rotateY(180deg) translateZ(24.25px)}
.h3d-nut .nf:nth-child(5){transform:rotateY(240deg) translateZ(24.25px)}
.h3d-nut .nf:nth-child(6){transform:rotateY(300deg) translateZ(24.25px)}
.h3d-nut .nc{position:absolute;left:-28px;top:-24.25px;width:56px;height:48.5px;clip-path:polygon(25% 0,75% 0,100% 50%,75% 100%,25% 100%,0 50%);
  background:radial-gradient(circle,#0f172a 0 22%,#92400e 23% 30%,#fef3c7 31%,#f59e0b 85%)}
.h3d-nut .nc.t{transform:rotateX(90deg) translateZ(11px)}
.h3d-nut .nc.b{transform:rotateX(-90deg) translateZ(11px)}
.h3d-shadow{position:absolute;left:50%;bottom:10px;width:190px;height:26px;margin-left:-95px;border-radius:50%;background:radial-gradient(ellipse,rgba(15,23,42,.4),transparent 70%);filter:blur(4px);transform:scale(var(--ss,1));opacity:var(--so,.8)}

/* ======= تیتر بخش‌ها ======= */
.sec-head{display:flex;align-items:center;gap:18px;margin-bottom:20px}
.sec-head h2{font-size:24px;font-weight:900;color:var(--ink)}
.sec-head p{font-size:12px;color:var(--muted);margin-top:2px}
.sec-head .bar{flex:1;height:4px;background:var(--line);border-radius:99px}
.sec-head .more{font-size:12px;font-weight:800;color:var(--gold-deep);white-space:nowrap}
.sec-head .more:hover{text-decoration:underline}

/* ======= دسته‌بندی‌ها ======= */
.cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(125px,1fr));gap:14px}
.cat{background:#fff;border:1px solid var(--line);border-radius:18px;padding:18px 10px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:6px;box-shadow:0 1px 3px rgba(15,23,42,.05)}
.cat:hover{border-color:var(--gold-light)}
.cat .ic{width:64px;height:64px;border-radius:18px;display:grid;place-items:center;font-size:30px;margin-bottom:4px;border:2px solid var(--rc,#fcd34d);background:var(--bg,#fffbeb);box-shadow:0 6px 14px -6px rgba(15,23,42,.25);transition:transform .3s}
.cat:hover .ic{transform:scale(1.1)}
.cat b{font-size:12.5px;font-weight:900;color:#1e293b}
.cat:hover b{color:var(--gold-deep)}
.cat small{font-size:11px;color:var(--muted)}
.cat:nth-child(8n+1){--rc:#fcd34d;--bg:#fffbeb}.cat:nth-child(8n+2){--rc:#93c5fd;--bg:#eff6ff}
.cat:nth-child(8n+3){--rc:#6ee7b7;--bg:#ecfdf5}.cat:nth-child(8n+4){--rc:#c4b5fd;--bg:#f5f3ff}
.cat:nth-child(8n+5){--rc:#fca5a5;--bg:#fef2f2}.cat:nth-child(8n+6){--rc:#67e8f9;--bg:#ecfeff}
.cat:nth-child(8n+7){--rc:#fdba74;--bg:#fff7ed}.cat:nth-child(8n+8){--rc:#94a3b8;--bg:#f8fafc}

/* ======= بنرهای فروش سازمانی / عمده ======= */
.b2b{display:grid;grid-template-columns:1fr 1fr;gap:22px}
.bn{position:relative;overflow:hidden;border-radius:26px;padding:32px;min-height:220px;display:flex;flex-direction:column;justify-content:space-between;box-shadow:0 18px 34px -18px rgba(15,23,42,.5)}
.bn-1{background:linear-gradient(135deg,#fbbf24,#f59e0b 50%,#ca8a04);color:var(--ink)}
.bn-2{background:linear-gradient(135deg,#1e293b,#0f172a 55%,#020617);color:#fff;border:1px solid #1e293b}
.bn .wm{position:absolute;left:-10px;bottom:-26px;font-size:140px;line-height:1;opacity:.2;pointer-events:none}
.bn>*{position:relative}.bn .wm{position:absolute}
.bn .tag{align-self:flex-start;font-size:11px;font-weight:900;padding:4px 14px;border-radius:99px}
.bn-1 .tag{background:var(--navy-deep);color:var(--gold-light)}
.bn-2 .tag{background:var(--gold-light);color:var(--ink)}
.bn h3{font-size:24px;font-weight:900;margin:10px 0 6px}
.bn p{font-size:12.5px;line-height:1.9;max-width:440px;font-weight:700}
.bn-2 p{color:#cbd5e1;font-weight:500}
.bn .go{align-self:flex-start;margin-top:16px;padding:11px 24px;border-radius:12px;font-size:12.5px;font-weight:900;box-shadow:0 6px 14px -6px rgba(0,0,0,.4);transition:transform .2s}
.bn .go:hover{transform:translateY(-2px)}
.bn-1 .go{background:var(--navy-deep);color:#fff}
.bn-2 .go{background:var(--gold-light);color:var(--ink)}

/* ======= پنل محصولات ======= */
.panel{background:#fff;border:1px solid #e2e8f0cc;border-radius:26px;padding:26px 28px;box-shadow:0 1px 3px rgba(15,23,42,.05)}
.panel-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px;border-bottom:1px solid #f1f5f9;padding-bottom:16px;margin-bottom:22px}
.panel-head .t{display:flex;align-items:center;gap:12px}
.panel-head .t i{display:block;width:16px;height:32px;border-radius:6px;background:var(--gold-light)}
.panel-head h2{font-size:20px;font-weight:900}
.panel-head p{font-size:12px;color:#94a3b8}
.timer{display:flex;align-items:center;gap:6px;background:var(--navy-deep);color:#fff;padding:8px 16px;border-radius:16px;font-size:12px;font-weight:800}
.timer .lbl{color:var(--gold-light);margin-left:6px}
.timer b{background:var(--navy);color:var(--gold-light);padding:3px 9px;border-radius:9px;min-width:30px;text-align:center;font-weight:900}
.section-more{text-align:center;margin-top:24px}
.section-more a{display:inline-block;padding:10px 30px;border:2px solid var(--navy-deep);border-radius:12px;color:var(--navy-deep);font-weight:900;font-size:13px;transition:all .2s}
.section-more a:hover{background:var(--navy-deep);color:var(--gold-light)}

/* ======= کارت محصول ======= */
.products-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:20px}
.pc{position:relative;display:flex;flex-direction:column;background:#fff;border:1px solid #e2e8f0cc;border-radius:18px;padding:14px;transition:border-color .2s,box-shadow .3s}
.pc:hover{border-color:var(--gold-light);box-shadow:0 24px 34px -10px rgba(15,23,42,.18),0 14px 14px -8px rgba(15,23,42,.08)}
.pc-tag{position:absolute;top:12px;right:12px;z-index:3;background:var(--red);color:#fff;font-size:10.5px;font-weight:900;padding:3px 11px;border-radius:99px;box-shadow:0 2px 6px rgba(239,68,68,.4)}
.pc-tag-off{background:#64748b;box-shadow:none}
.pc-img{display:flex;align-items:center;justify-content:center;height:190px;background:#f8fafc;border-radius:14px;padding:14px;margin-bottom:14px;overflow:hidden}
.pc-img img{width:100%;height:100%;object-fit:contain;transition:transform .4s}
.pc:hover .pc-img img{transform:scale(1.06)}
.pc-off .pc-img img{filter:grayscale(.7)}
.pc-body{display:flex;flex-direction:column;gap:10px;flex:1}
.pc-title{font-size:12.5px;font-weight:900;line-height:1.8;height:45px;overflow:hidden;color:#1e293b}
.pc-title a:hover{color:var(--gold-deep)}
.pc-stars{display:flex;align-items:center;gap:1px;font-size:14px;color:#cbd5e1;min-height:22px}
.pc-stars .on{color:var(--gold-light)}
.pc-stars em{font-style:normal;font-size:10.5px;color:#94a3b8;margin-right:5px}
.pc-noreview{font-size:11px;color:#cbd5e1}
.pc-foot{margin-top:auto;padding-top:12px;border-top:1px solid #f1f5f9;display:flex;align-items:flex-end;justify-content:space-between;gap:8px}
.pc-price del{display:block;font-size:12px;color:#94a3b8;min-height:19px}
.pc-price del.ghost{visibility:hidden}
.pc-price b{font-size:15px;font-weight:900;color:var(--ink)}
.pc-price b small{font-size:10.5px;font-weight:500;color:var(--muted)}
.pc-call{color:#9f1239;font-size:13px!important}
.pc-add{width:44px;height:44px;border-radius:13px;background:var(--gold-light);color:var(--ink);display:grid;place-items:center;box-shadow:0 6px 12px -5px var(--gold);transition:background .2s,transform .2s}
.pc-add:hover{background:var(--gold);transform:scale(1.06)}
.pc-add:disabled{background:#e2e8f0;color:#94a3b8;box-shadow:none;cursor:not-allowed;transform:none}

/* ======= برندها ======= */
.brands{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
.brands a{display:flex;align-items:center;justify-content:center;padding:26px 10px;border-radius:18px;font-size:24px;font-weight:900;letter-spacing:.14em;box-shadow:0 6px 14px -8px rgba(15,23,42,.4);transition:transform .2s}
.brands a:hover{transform:scale(1.05)}
.br-1{background:#020617;color:#fff;border:1px solid #1e293b}.br-2{background:#f59e0b;color:var(--ink)}
.br-3{background:#1e3a8a;color:#fff}.br-4{background:#dc2626;color:#fff}

/* ======= Tilt ======= */
.tilt{transition:transform .2s cubic-bezier(.25,.46,.45,.94),box-shadow .3s,border-color .2s;transform-style:preserve-3d;will-change:transform}

/* ======= Toast ======= */
.toast{position:fixed;bottom:90px;left:50%;transform:translateX(-50%) translateY(100px);padding:12px 26px;border-radius:12px;font-size:14px;font-weight:700;color:#fff;box-shadow:0 14px 30px -8px rgba(0,0,0,.35);z-index:9999;opacity:0;transition:all .4s;border-bottom:3px solid var(--gold-light)}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast.success{background:var(--navy-deep)}.toast.error{background:#9f1239}

/* ======= ریسپانسیو ======= */
@media(max-width:992px){
  .hero{flex-direction:column;padding:30px 24px}
  .hero-visual{min-height:300px;width:100%}
  .hero h1{font-size:32px}
  .b2b{grid-template-columns:1fr}
}
@media(max-width:768px){
  main.ab{gap:30px}
  .hero h1{font-size:26px}
  .panel{padding:18px 14px}
  .products-grid{grid-template-columns:repeat(2,1fr);gap:12px}
  .pc{padding:10px}.pc-img{height:130px;padding:8px}
  .pc-title{font-size:12px;height:43px}
  .pc-price b{font-size:13px}
  .pc-add{width:38px;height:38px}
  .brands{grid-template-columns:repeat(2,1fr)}
  .brands a{font-size:18px;padding:20px 8px}
  .sec-head h2{font-size:20px}
}
@media(max-width:400px){.products-grid{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){.tilt,.hc::after{transition:none;animation:none}}
</style>
</head>
<body>

<?php $__phone = trim((string)site_get($pdo, 'site_phone', '')); ?>
<div class="ab-top"><div class="in">
  <div class="side">
    <?php if ($__phone !== ''): ?><span>📞 مشاوره و فروش: <?= ms_fa_digits(htmlspecialchars($__phone)) ?></span><span>|</span><?php endif; ?>
    <span>🚚 ارسال سریع به سراسر کشور</span>
  </div>
  <div class="mid">⚡ جشنواره پیشنهادهای ویژه ابزارآلات صنعتی و خانگی!</div>
  <div class="side"><a href="account.php">پیگیری سفارش</a><span>|</span><a href="contact.php">تماس با ما</a></div>
</div></div>

<?php require __DIR__ . '/includes/site_header.php'; ?>

<?php
$__hd = ['badge' => 'ضمانت اصالت کالا + ارسال سریع',
  't1' => 'مرجع تخصصی خرید', 't2' => 'ابزارآلات صنعتی و خانگی',
  'sub' => 'دسترسی سریع به ' . ms_fa_digits(number_format((int)$totalProducts)) . ' کالا از معتبرترین برندها با بهترین قیمت بازار و ارسال سریع به سراسر کشور.',
  'b1' => 'مشاهده کاتالوگ محصولات', 'b1u' => 'archive.php', 'b2' => 'پیشنهادهای شگفت‌انگیز', 'b2u' => 'archive.php?sort=discount',
  'c1tag' => 'فروش ویژه شرکت‌ها', 'c1t' => 'دپارتمان فروش سازمانی',
  'c1s' => 'صدور فاکتور رسمی و تأمین تخصصی ابزارآلات برای پروژه‌های عمرانی، صنعتی و کارگاهی با شرایط ویژه.',
  'c1l' => 'درخواست پیش‌فاکتور', 'c1u' => 'contact.php',
  'c2tag' => 'همکاری با فروشگاه‌ها', 'c2t' => 'دپارتمان فروش عمده و همکار',
  'c2s' => 'تجهیز فروشگاه‌های ابزار سراسر کشور با قیمت ویژه همکار و شرایط پرداخت انعطاف‌پذیر.',
  'c2l' => 'ثبت‌نام حساب همکاری', 'c2u' => 'contact.php'];
$__hs = json_decode(site_get($pdo, 'home_hero', ''), true);
if (is_array($__hs)) foreach ($__hs as $__k => $__v) if (isset($__hd[$__k]) && trim((string)$__v) !== '') $__hd[$__k] = (string)$__v;
$__u = function ($u) { return preg_match('#^(https?://|[A-Za-z0-9_./?=&%-]+$)#', $u) ? htmlspecialchars($u) : 'archive.php'; };
$__e = function ($k) use ($__hd) { return htmlspecialchars($__hd[$k]); };
?>

<main class="ab container">

  <!-- ============ Hero ============ -->
  <section class="hero" id="hero">
    <div class="hero-text">
      <span class="hero-badge">🛡️ <?= $__e('badge') ?></span>
      <h1><?= $__e('t1') ?> <span><?= $__e('t2') ?></span></h1>
      <p class="sub"><?= $__e('sub') ?></p>
      <div class="hero-btns">
        <a href="<?= $__u($__hd['b1u']) ?>" class="hb hb-1"><?= $__e('b1') ?> ←</a>
        <a href="<?= $__u($__hd['b2u']) ?>" class="hb hb-2"><?= $__e('b2') ?></a>
      </div>
      <div class="hero-stats">
        <span>🧰 <?= ms_fa_digits(number_format((int)$totalProducts)) ?> محصول</span>
        <span>✅ <?= ms_fa_digits(number_format((int)$totalInStock)) ?> کالای موجود</span>
      </div>
      <?php if (!empty($heroCoupons)): ?>
      <div class="hero-coupons">
        <div class="hc-title">🎟 کد تخفیف فعال — روی کد کلیک کنید تا کپی شود</div>
        <div class="hc-list">
          <?php foreach ($heroCoupons as $ci => $cp): ?>
          <button type="button" class="hc hc-<?= $ci % 4 ?>" data-code="<?= htmlspecialchars($cp['code']) ?>" title="کپی کد تخفیف">
            <span class="hc-val"><?= $cp['type'] === 'percent' ? ms_fa_digits((int)$cp['value']) . '٪ تخفیف' : ms_fa_digits(number_format((int)$cp['value'])) . ' تومان تخفیف' ?></span>
            <span class="hc-code"><?= htmlspecialchars($cp['code']) ?></span>
            <small><?= $cp['title'] ? htmlspecialchars($cp['title']) . ' • ' : '' ?>تا <?= ms_fa_digits(jalali_str($cp['ends_at'], false)) ?><?= $cp['n_products'] ? ' • روی ' . ms_fa_digits($cp['n_products']) . ' کالا' : '' ?></small>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <div class="hero-visual" id="heroVisual" aria-hidden="true"></div>
  </section>

  <!-- ============ دسته‌بندی‌ها ============ -->
  <?php if (!empty($categories)): ?>
  <section>
    <div class="sec-head">
      <div><h2>دسته‌بندی‌های تخصصی</h2><p>انتخاب ابزار بر اساس حوزه کاری شما</p></div>
      <div class="bar"></div>
      <a class="more" href="archive.php">همه محصولات ←</a>
    </div>
    <div class="cats">
      <?php foreach ($categories as $i => $c): ?>
      <a href="archive.php?cat=<?= (int)$c['id'] ?>" class="cat tilt">
        <span class="ic"><?= $catIcons[$i % count($catIcons)] ?></span>
        <b><?= htmlspecialchars($c['name']) ?></b>
        <small><?= ms_fa_digits(number_format($c['product_count'])) ?> محصول</small>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ============ بنرهای فروش سازمانی / عمده ============ -->
  <section class="b2b">
    <div class="bn bn-1 tilt"><span class="wm">🏢</span>
      <div><span class="tag"><?= $__e('c1tag') ?></span><h3><?= $__e('c1t') ?></h3><p><?= $__e('c1s') ?></p></div>
      <a href="<?= $__u($__hd['c1u']) ?>" class="go"><?= $__e('c1l') ?> ‹</a>
    </div>
    <div class="bn bn-2 tilt"><span class="wm">🤝</span>
      <div><span class="tag"><?= $__e('c2tag') ?></span><h3><?= $__e('c2t') ?></h3><p><?= $__e('c2s') ?></p></div>
      <a href="<?= $__u($__hd['c2u']) ?>" class="go"><?= $__e('c2l') ?> ‹</a>
    </div>
  </section>

  <!-- ============ پیشنهادهای شگفت‌انگیز (با شمارش معکوس تا پایان امروز) ============ -->
  <?php if (!empty($discountProducts)): ?>
  <section class="panel">
    <div class="panel-head">
      <div class="t"><i></i><div><h2>پیشنهادهای شگفت‌انگیز</h2><p>تخفیف‌های ویژه ابزارآلات برتر</p></div></div>
      <div class="timer" dir="rtl"><span class="lbl">⏱ زمان باقی‌مانده:</span>
        <b id="tH">۰۰</b>:<b id="tM">۰۰</b>:<b id="tS">۰۰</b></div>
    </div>
    <div class="products-grid">
      <?php foreach ($discountProducts as $p) ms_product_card($p, true); ?>
    </div>
    <div class="section-more"><a href="archive.php?sort=discount">مشاهده همه تخفیف‌ها</a></div>
  </section>
  <?php endif; ?>

  <!-- ============ پیشنهاد ویژه ============ -->
  <?php if (!empty($featuredProducts)): ?>
  <section class="panel">
    <div class="panel-head">
      <div class="t"><i></i><div><h2>پیشنهاد ویژه</h2><p>منتخب کارشناسان فروشگاه</p></div></div>
    </div>
    <div class="products-grid">
      <?php foreach ($featuredProducts as $p) ms_product_card($p); ?>
    </div>
    <div class="section-more"><a href="archive.php">مشاهده همه محصولات</a></div>
  </section>
  <?php endif; ?>

  <!-- ============ جدیدترین‌ها ============ -->
  <?php if (!empty($newestProducts)): ?>
  <section class="panel">
    <div class="panel-head">
      <div class="t"><i></i><div><h2>جدیدترین محصولات</h2><p>تازه‌های اضافه‌شده به فروشگاه</p></div></div>
    </div>
    <div class="products-grid">
      <?php foreach ($newestProducts as $p) ms_product_card($p); ?>
    </div>
    <div class="section-more"><a href="archive.php">مشاهده همه محصولات</a></div>
  </section>
  <?php endif; ?>

  <!-- ============ برندها ============ -->
  <section class="panel">
    <h2 style="text-align:center;font-size:18px;font-weight:900;margin-bottom:20px">برندهای برتر بین‌المللی و ایرانی</h2>
    <div class="brands">
      <a href="search.php?q=BOSCH" class="br-1 tilt">BOSCH</a>
      <a href="search.php?q=DEWALT" class="br-2 tilt">DEWALT</a>
      <a href="search.php?q=MAKITA" class="br-3 tilt">MAKITA</a>
      <a href="search.php?q=RONIX" class="br-4 tilt">RONIX</a>
    </div>
  </section>

</main>

<?php require __DIR__ . '/includes/site_footer.php'; ?>

<script>
/* ---------- کپی کد تخفیف ---------- */
document.querySelectorAll('.hc').forEach(function(b){b.addEventListener('click',function(){
  var c=b.dataset.code, done=function(){b.classList.add('hc-ok');var s=b.querySelector('small'),o=s.textContent;s.textContent='✔ کپی شد';setTimeout(function(){b.classList.remove('hc-ok');s.textContent=o},1600)};
  if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(c).then(done,done)}else{var t=document.createElement('textarea');t.value=c;document.body.appendChild(t);t.select();try{document.execCommand('copy')}catch(e){}t.remove();done()}
})});

/* ---------- افزودن به سبد ---------- */
function addToCart(productId, event) {
  if (event) event.preventDefault();
  fetch('add_to_cart.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id=' + productId})
  .then(function(r){return r.json()})
  .then(function(data){
    if (data.success) {
      if (data.cart_count > 0) {
        var fa = String(data.cart_count).replace(/\d/g, function(d){return '۰۱۲۳۴۵۶۷۸۹'[d]});
        var badge = document.querySelector('.sh2-badge, .cart-badge');
        if (badge) badge.textContent = fa;
      }
      showToast('✅ ' + data.message, false);
    } else { showToast('❌ ' + data.message, true); }
  })
  .catch(function(){ showToast('خطا در ارتباط با سرور', true); });
}
function showToast(message, isError) {
  var ex = document.querySelector('.toast'); if (ex) ex.remove();
  var t = document.createElement('div');
  t.className = 'toast ' + (isError ? 'error' : 'success'); t.textContent = message;
  document.body.appendChild(t);
  requestAnimationFrame(function(){ t.classList.add('show'); });
  setTimeout(function(){ t.classList.remove('show'); setTimeout(function(){ t.remove(); }, 400); }, 2500);
}

(function () {
  'use strict';
  var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = matchMedia('(hover:hover) and (pointer:fine)').matches;
  function fa(n){return String(n).replace(/\d/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[d]})}

  /* ---------- شمارش معکوس تا پایان امروز ---------- */
  var tH=document.getElementById('tH');
  if (tH) {
    var tM=document.getElementById('tM'), tS=document.getElementById('tS');
    (function tick(){
      var n=new Date(), end=new Date(n); end.setHours(23,59,59,999);
      var s=Math.max(0,Math.floor((end-n)/1000));
      tH.textContent=fa(('0'+Math.floor(s/3600)).slice(-2));
      tM.textContent=fa(('0'+Math.floor(s%3600/60)).slice(-2));
      tS.textContent=fa(('0'+s%60).slice(-2));
      setTimeout(tick,1000);
    })();
  }

  /* ---------- کج‌شدن سه‌بعدی کارت‌ها (مثل نمونه) ---------- */
  if (fine && !reduce) {
    document.querySelectorAll('.tilt').forEach(function (card) {
      card.addEventListener('mousemove', function (e) {
        var r = card.getBoundingClientRect(), cx = r.width/2, cy = r.height/2;
        var rx = ((e.clientY - r.top - cy) / cy) * -7, ry = ((e.clientX - r.left - cx) / cx) * 7;
        card.style.transform = 'perspective(1000px) rotateX(' + rx.toFixed(2) + 'deg) rotateY(' + ry.toFixed(2) + 'deg) translateZ(10px)';
      });
      card.addEventListener('mouseleave', function () {
        card.style.transform = 'perspective(1000px) rotateX(0deg) rotateY(0deg) translateZ(0)';
      });
    });
  }

  /* ---------- چرخ‌دنده فلزی + مهره سه‌بعدی در Hero ---------- */
  var box = document.getElementById('heroVisual'), hero = document.getElementById('hero');
  if (!box) return;

  function gearPath() {
    var N=12, Ro=50, Rr=41, pts=[];
    function p(a,r){var t=a*Math.PI/180;return (50+r*Math.cos(t)).toFixed(2)+'% '+(50+r*Math.sin(t)).toFixed(2)+'%'}
    for (var k=0;k<N;k++){var a=k*360/N;pts.push(p(a-11,Rr),p(a-7,Rr),p(a-4.5,Ro),p(a+4.5,Ro),p(a+7,Rr),p(a+11,Rr));}
    var hole=[];for(var h=0;h<28;h++)hole.push(p(h*360/28,15));
    return 'polygon(evenodd,'+pts.join(',')+','+pts[0]+','+hole.join(',')+','+hole[0]+','+pts[0]+')';
  }
  var layers='', L=28;
  for (var i=0;i<L;i++){var cls=i===L-1?'gl gf':(i===0?'gl gb':'gl');layers+='<div class="'+cls+'" style="transform:translateZ('+(i-(L-1)/2)+'px)"></div>';}
  var nut=''; for (var s=0;s<6;s++) nut+='<div class="nf"></div>';
  nut+='<div class="nc t"></div><div class="nc b"></div>';
  box.innerHTML='<div class="h3d-shadow"></div><div class="h3d-tool"><div class="h3d-gear">'+layers+'</div><div class="h3d-nutpos"><div class="h3d-nut">'+nut+'</div></div></div>';
  var tool=box.querySelector('.h3d-tool'), gear=box.querySelector('.h3d-gear'),
      nutPos=box.querySelector('.h3d-nutpos'), nutEl=box.querySelector('.h3d-nut'), shadow=box.querySelector('.h3d-shadow');
  gear.style.setProperty('--gear', gearPath());

  var tx=0, ty=0, cx=0, cy=0, t0=performance.now(), visible=true;
  if (fine && !reduce) {
    hero.addEventListener('pointermove', function(e){
      var r=hero.getBoundingClientRect();
      tx=((e.clientX-r.left)/r.width-.5)*2; ty=((e.clientY-r.top)/r.height-.5)*2;
    });
    hero.addEventListener('pointerleave', function(){tx=0;ty=0});
  }
  if ('IntersectionObserver' in window) new IntersectionObserver(function(en){visible=en[0].isIntersecting;if(visible&&!reduce)requestAnimationFrame(frame)}).observe(hero);

  function frame(now){
    if(!visible && !reduce) return;
    var t=(now-t0)/1000;
    cx+=(tx-cx)*.08; cy+=(ty-cy)*.08;
    var bob=reduce?0:Math.sin(t*1.2)*6, ry=-24+(reduce?0:Math.sin(t*.6)*22)+cx*30, rx=14-cy*16;
    tool.style.transform='translateY('+bob.toFixed(1)+'px) rotateX('+rx.toFixed(1)+'deg) rotateY('+ry.toFixed(1)+'deg)';
    gear.style.transform='rotateZ('+(reduce?0:(t*14)%360).toFixed(1)+'deg)';
    nutPos.style.transform='translate3d('+(96+Math.cos(t*.8)*10).toFixed(1)+'px,'+(112+Math.sin(t*1.1)*8).toFixed(1)+'px,70px)';
    nutEl.style.transform='rotateX('+((reduce?25:t*50)%360)+'deg) rotateY('+((reduce?20:t*80)%360)+'deg)';
    shadow.style.setProperty('--ss',(1-bob/40).toFixed(3)); shadow.style.setProperty('--so',(.8-bob/40).toFixed(3));
    if(!reduce) requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
})();
</script>
</body>
</html>
