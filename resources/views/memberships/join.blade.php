{{--
    The page a customer joins the membership program from: the signed link in
    the offer after a good review, and in the renewal reminder. UI queue 54,
    Jose's standing word for the program ("go with ur recommended options as
    usual"): option 1, which works as option 4 on a phone.

    It wears the frame every business email through EzBizi wears (queue 39):
    the business's name in a white band over a teal rule, "via EzBizi" small,
    so it reads as the same business as the message that linked here. A plan
    is a card with its price large and one Join. The stored price is the
    YEAR's (Membership::dueMinor bills a twelfth a month on a monthly plan),
    so a monthly plan shows the month and the year and never the year's price
    as a month's. Everything here is escaped by Blade.
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $company }} membership</title>
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
.plan{margin:0;border:1px solid #E3E9EC;border-radius:10px;padding:12px 14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.name{font-weight:700;color:#060808}
.what{font-size:13px;color:#5B6672}
.price{margin-left:auto;font-size:21px;font-weight:800;color:#060808;font-variant-numeric:tabular-nums;white-space:nowrap}
.price small{font-size:12.5px;font-weight:600;color:#5B6672}
button{border:0;border-radius:10px;background:#00708F;color:#fff;font:inherit;font-weight:700;padding:10px 20px;cursor:pointer}
button:hover{background:#00485C}
button:focus-visible{outline:3px solid #00B4D8;outline-offset:2px}
.done{background:#E1F2E5;color:#16662D;border-radius:10px;padding:12px 14px}
@media (max-width:480px){
  .plan > div:first-child{flex:1 1 100%}
  .price{margin-left:0}
  button{width:100%}
}
</style>
</head>
<body><main>
<div class="band"><b>{{ $company }}</b><span class="via">via EzBizi</span></div>
<div class="body">
@if ($current)
  <div class="done"><strong>You're a member, {{ $customer->name }}.</strong><br>{{ $current->plan->name }}, until {{ $current->ends_on->format('F j, Y') }}. We'll remind you a month before then to book your next visit.</div>
@elseif ($plans->isEmpty())
  <h1>Yearly maintenance</h1>
  <p class="note">Sign-up isn't open yet. Please call us.</p>
@else
  <h1>Yearly maintenance</h1>
  <p>Hi {{ $customer->name }}, join our membership program and we'll look after your yearly visit. We'll remind you a month before it's due.</p>
  @foreach ($plans as $plan)
    @php
      // Whole units unless there are cents: "$18", "$16.58".
      $money = fn (float $units) => ($plan->currency === 'USD' || blank($plan->currency) ? '$' : $plan->currency.' ')
          .number_format($units, round($units * 100) % 100 === 0 ? 0 : 2);
      $year = $plan->price_minor / 100;
    @endphp
    <form class="plan" method="post">
      <div>
        <div class="name">{{ $plan->name }}</div>
        @if (filled($plan->description ?? null))
          <div class="what">{{ $plan->description }}</div>
        @endif
      </div>
      <div class="price">
        @if ($plan->billing === 'monthly')
          {{ $money($year / 12) }} <small>a month · {{ $money($year) }} a year</small>
        @else
          {{ $money($year) }} <small>a year</small>
        @endif
      </div>
      <input type="hidden" name="plan" value="{{ $plan->id }}">
      <button type="submit">Join</button>
    </form>
  @endforeach
@endif
</div>
</main></body>
</html>
