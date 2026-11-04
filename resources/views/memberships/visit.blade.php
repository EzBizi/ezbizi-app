{{--
    The renewal reminder's link: the customer picks the day of their next
    yearly visit and it books a job (Jose, 2026-10-05). Same frame as
    join.blade.php (UI queue 54), so it reads as the same business; the look is
    UI/UX's to change. A native date input, so a phone shows its own picker.
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $company }}: your next visit</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:24px 12px;background:#F5F8FA;color:#1D2A33;font:15px/1.55 -apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
main{max-width:520px;margin:0 auto}
.band{background:#fff;border:1px solid #E3E9EC;border-bottom:3px solid #00B4D8;border-radius:12px 12px 0 0;padding:14px 18px;display:flex;align-items:center;gap:10px}
.band b{font-size:18px;font-weight:800;color:#060808}
.via{margin-left:auto;font-size:11px;color:#5B6672;white-space:nowrap}
.body{background:#fff;border:1px solid #E3E9EC;border-top:0;border-radius:0 0 12px 12px;padding:18px;display:flex;flex-direction:column;gap:12px}
h1{margin:0;font-size:19px;color:#060808}
p{margin:0}
.note{color:#5B6672}
form{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
input[type=date]{font:inherit;padding:9px 10px;border:1px solid #C9D3D8;border-radius:10px}
button{border:0;border-radius:10px;background:#00708F;color:#fff;font:inherit;font-weight:700;padding:10px 20px;cursor:pointer}
button:hover{background:#00485C}
button:focus-visible,input:focus-visible{outline:3px solid #00B4D8;outline-offset:2px}
.done{background:#E1F2E5;color:#16662D;border-radius:10px;padding:12px 14px}
.error{color:#A1260D}
</style>
</head>
<body><main>
<div class="band"><b>{{ $company }}</b><span class="via">via EzBizi</span></div>
<div class="body">
@if ($job)
  <div class="done"><strong>You're booked, {{ $customer?->name }}.</strong><br>
    Your yearly visit is on {{ \Carbon\CarbonImmutable::parse($job->scheduled_for)->format('l, F j, Y') }}. We'll be in touch to confirm the time.</div>
@elseif ($membership->cancelled_at)
  <h1>Your next visit</h1>
  <p class="note">This membership has ended. Please call us to book.</p>
@else
  <h1>Your next yearly visit</h1>
  <p>Hi {{ $customer?->name }}, your {{ $membership->plan->name }} year runs until {{ $membership->ends_on->format('F j, Y') }}. Pick the day that suits you for your next visit.</p>
  @if ($error)<p class="error">{{ $error }}</p>@endif
  <form method="post">
    <label for="visit-date">Day of your visit</label>
    <input type="date" id="visit-date" name="date" required min="{{ now()->addDay()->toDateString() }}" max="{{ $lastDay }}" value="{{ max($membership->ends_on->toDateString(), now()->addDay()->toDateString()) }}">
    <button type="submit">Book this day</button>
  </form>
@endif
</div>
</main></body>
</html>
