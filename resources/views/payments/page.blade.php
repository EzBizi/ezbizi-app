{{--
    Where Stripe returns a browser (PaymentPagesController). Same frame as the
    membership pages: the business's name in a white band over a teal rule,
    "via EzBizi" small. Everything is escaped by Blade.
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="robots" content="noindex">
<title>{{ $title }}</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:24px 12px;background:#F5F8FA;color:#1D2A33;font:15px/1.55 -apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
main{max-width:520px;margin:0 auto}
.band{background:#fff;border:1px solid #E3E9EC;border-bottom:3px solid #00B4D8;border-radius:12px 12px 0 0;padding:14px 18px;display:flex;align-items:center;gap:10px}
.band b{font-size:18px;font-weight:800;color:#060808}
.via{margin-left:auto;font-size:11px;color:#5B6672;white-space:nowrap}
.body{background:#fff;border:1px solid #E3E9EC;border-top:0;border-radius:0 0 12px 12px;padding:18px;display:flex;flex-direction:column;gap:10px}
h1{margin:0;font-size:19px;color:#060808}
p{margin:0}
.amount{font-size:26px;font-weight:800;color:#060808}
</style>
</head>
<body>
<main>
    <div class="band"><b>{{ $business ?? 'EzBizi' }}</b>@if($business)<span class="via">via EzBizi</span>@endif</div>
    <div class="body">
        <h1>{{ $title }}</h1>
        @if($amount)<div class="amount">{{ $amount }}</div>@endif
        <p>{{ $message }}</p>
    </div>
</main>
</body>
</html>
