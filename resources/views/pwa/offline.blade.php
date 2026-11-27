<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $settings['name'] }}</title>
    <style>
        [hidden] { display: none; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; box-sizing: border-box; background: #09090b; color: #fafafa; font: 16px system-ui, sans-serif; }
        main { max-width: 24rem; text-align: center; }
        button { padding: .7rem 1.25rem; border: 0; border-radius: .5rem; background: #e9bd63; color: #18181b; font: inherit; font-weight: 700; }
    </style>
</head>
<body>
    <main>
        <h1>{{ $settings['name'] }}</h1>
        <div id="zh-CN"><p>{{ $settings['offline_zh'] }}</p><button type="button">重试</button></div>
        <div id="pt-BR" hidden><p>{{ $settings['offline_pt'] }}</p><button type="button">Tentar novamente</button></div>
    </main>
    <script>
        let language
        try { language = localStorage.getItem('cash-club-language') } catch {}
        if (language === 'pt-BR' || (!language && navigator.language.toLowerCase().startsWith('pt'))) {
            document.documentElement.lang = 'pt-BR'
            document.getElementById('zh-CN').hidden = true
            document.getElementById('pt-BR').hidden = false
        }
        for (const button of document.querySelectorAll('button')) {
            button.addEventListener('click', () => location.reload())
        }
    </script>
</body>
</html>
