<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Scrapify Auctions API — Docs</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css" />
    <style>
        body { margin: 0; background: #fafafa; }
        .topbar { display: none !important; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>

    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js" crossorigin></script>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js" crossorigin></script>
    <script>
        window.onload = function () {
            window.ui = SwaggerUIBundle({
                url: "{{ route('api.docs.spec') }}",
                dom_id: '#swagger-ui',
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset,
                ],
                layout: 'StandaloneLayout',
                deepLinking: true,
                persistAuthorization: true,
            });
        };
    </script>
</body>
</html>
