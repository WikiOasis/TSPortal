<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>No access &middot; {{ config('app.name') }}</title>
	<style>
		body { font-family: sans-serif; margin: 0; padding: 4rem 1.5rem; color: #202122; background: #f8f9fa; }
		main { max-width: 34rem; margin: 0 auto; background: #fff; border: 1px solid #a2a9b1; border-radius: 2px; padding: 2rem; }
		h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
		p { line-height: 1.6; margin: 0 0 1rem; }
		a { color: #3366cc; }
	</style>
</head>
<body>
	<main>
		<h1>You do not have access to this</h1>
		<p>{{ $message ?? 'Your account cannot see this part of the portal.' }}</p>
		<p><a href="/">Back to the portal</a></p>
	</main>
</body>
</html>
