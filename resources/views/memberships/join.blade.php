<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $company }} membership</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:0;padding:24px 16px;background:#f6f7f9;color:#1b1b1b}
main{max-width:520px;margin:0 auto}
h1{font-size:1.5rem;margin:0 0 8px}
.plan{background:#fff;border:1px solid #dde1e6;border-radius:10px;padding:16px;margin:12px 0;display:flex;justify-content:space-between;align-items:center;gap:12px}
.price{font-weight:700}
button{padding:10px 18px;border:0;border-radius:6px;background:#1f6feb;color:#fff;font:inherit;font-weight:600;cursor:pointer}
.done{background:#fff;border:1px solid #dde1e6;border-radius:10px;padding:16px}
</style>
</head>
<body><main>
<h1>{{ $company }} yearly maintenance</h1>
@if ($current)
  <div class="done">
    <p>Thank you, {{ $customer->name }}. You're a member on the <strong>{{ $current->plan->name }}</strong> plan until {{ $current->ends_on->format('F j, Y') }}.</p>
    <p>We'll remind you a month before then to book your next visit.</p>
  </div>
@elseif ($plans->isEmpty())
  <p>Sign-up isn't open yet. Please call us.</p>
@else
  <p>Hi {{ $customer->name }}, join our membership program and we'll look after your yearly visit.</p>
  @foreach ($plans as $plan)
    <form class="plan" method="post">
      <div>
        <div>{{ $plan->name }}</div>
        <div class="price">{{ $plan->currency }} {{ number_format($plan->price_minor / 100, 2) }} {{ $plan->billing === 'monthly' ? 'a year, paid monthly' : 'a year' }}</div>
      </div>
      <input type="hidden" name="plan" value="{{ $plan->id }}">
      <button type="submit">Join</button>
    </form>
  @endforeach
@endif
</main></body>
</html>
