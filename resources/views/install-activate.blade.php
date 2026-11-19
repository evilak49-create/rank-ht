<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>打开返利网安装向导</title>
</head>
<body>
<main>
    <h1>打开安装向导</h1>
    <p id="status" role="status">正在验证安装链接。</p>
    <noscript>请启用 JavaScript，再从服务器终端打开完整的安装链接。</noscript>
</main>
@php
    $activateUrl = route('site-install.activate', absolute: false);
    $formUrl = route('site-install.show', absolute: false);
@endphp
<script nonce="{{ $nonce }}">
    function activateInstallation() {
        const status = document.getElementById('status');
        const link = window.location.hash.slice(1);
        if (!/^[a-f0-9]{64}$/.test(link)) {
            status.textContent = '请从服务器终端打开完整的安装链接。';
            return;
        }

        history.replaceState(null, '', window.location.pathname);
        fetch(@json($activateUrl), {
            method: 'POST',
            body: new URLSearchParams({link}),
        }).then(response => {
            if (!response.ok) {
                status.textContent = '安装链接已使用、失效或过期。请在服务器重新生成链接。';
                return;
            }

            window.location.replace(@json($formUrl));
        }).catch(() => {
            status.textContent = '连接失败，请重新打开服务器终端生成的安装链接。';
        });
    }

    activateInstallation();
</script>
</body>
</html>
