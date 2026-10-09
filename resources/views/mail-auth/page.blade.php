{{--
    The page a browser lands on after a Google or Microsoft mail sign-in: one
    column, no assets, everything escaped. Served from the API origin, where
    there is no stylesheet to reach for and nothing should be fetched. Both
    values are escaped and capped because the sentence can carry an address
    somebody else chose (the wrong-account case).

    The viewport line and the `max-width` are the whole of R7 here: somebody
    finishing a sign-in is very often doing it on a phone, and 375px is the
    width this has to read at.
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>EzBizi — {{ mb_substr($heading, 0, 2000) }}</title>
<style>
body { margin: 0; padding: 2rem 1.25rem; background: #f6f7f9; color: #1c1e21;
       font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
main { max-width: 34rem; margin: 0 auto; background: #fff; border: 1px solid #e2e5e9;
       border-radius: 10px; padding: 1.5rem; }
h1 { margin: 0 0 .75rem; font-size: 1.25rem; }
p { margin: 0; }
</style>
</head>
<body>
<main>
<h1>{{ mb_substr($heading, 0, 2000) }}</h1>
<p>{{ mb_substr($sentence, 0, 2000) }}</p>
</main>
</body>
</html>
