<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>安装返利网</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef2f3; color: #182730; font: 15px/1.55 "Microsoft YaHei", "PingFang SC", sans-serif; }
        main { width: min(100% - 32px, 760px); margin: 44px auto; padding: clamp(22px, 6vw, 44px); background: #fff; border: 1px solid #dce5e8; border-top: 4px solid #176950; border-radius: 16px; box-shadow: 0 12px 36px #243d4210; }
        h1 { margin: 0 0 8px; font-size: clamp(26px, 5vw, 34px); line-height: 1.18; }
        p { margin: 0 0 18px; }
        .description, small { color: #50636e; }
        small { display: block; margin-top: 4px; }
        .fields { display: grid; gap: 14px; max-width: 420px; margin: 22px 0; }
        label { display: block; font-weight: 600; margin-bottom: 4px; }
        input { width: 100%; border: 1px solid #aabac2; border-radius: 7px; min-height: 43px; padding: 8px 11px; font: inherit; color: inherit; }
        [aria-invalid=true] { border-color: #a52d25; }
        input:focus-visible, button:focus-visible, a:focus-visible { outline: 3px solid #268d70; outline-offset: 2px; }
        .error { display: block; color: #a52d25; margin-top: 4px; }
        .notice { padding: 12px 16px; background: #fff1ed; color: #842a20; border-radius: 8px; margin-bottom: 20px; }
        button, .button { display: inline-block; background: #176950; color: #fff; border: 0; border-radius: 8px; padding: 10px 20px; font: inherit; font-weight: 700; cursor: pointer; text-decoration: none; }
        button:hover, .button:hover { background: #104f3d; }
        @media (max-width: 540px) { main { margin: 18px auto; } }
    </style>
</head>
<body>
<main>
    @if ($installed)
        <h1>安装完成</h1>
        <p class="description">数据库和后台账号已就绪。请使用刚创建的账号和密码登录。</p>
        <a class="button" href="{{ route('filament.admin.auth.login', absolute: false) }}">前往运营后台</a>
    @else
        <h1>安装返利网</h1>
        <p class="description">创建首个运营后台账号。</p>

        @if (isset($errors['install']))
            <p class="notice" role="alert">{{ $errors['install'][0] }}</p>
        @elseif ($errors !== [])
            <p class="notice" role="alert">请检查标记的字段，然后重新输入密码。</p>
        @endif

        <form method="post" action="{{ route('site-install.store', absolute: false) }}">
            <input type="hidden" name="_token" value="{{ $csrf }}">
            <div class="fields">
                <div>
                    <label for="username">账号</label>
                    <input id="username" name="username" type="text" autocomplete="username" maxlength="64"
                           value="{{ is_string($values['username'] ?? null) ? $values['username'] : '' }}"
                           @if (isset($errors['username'])) aria-invalid="true" aria-describedby="username-error" @endif required>
                    @if (isset($errors['username']))
                        <span class="error" id="username-error">{{ $errors['username'][0] }}</span>
                    @endif
                </div>
                <div>
                    <label for="password">密码</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" minlength="6"
                           @if (isset($errors['password'])) aria-invalid="true" aria-describedby="password-error"
                           @else aria-describedby="password-hint" @endif required>
                    @if (isset($errors['password']))
                        <span class="error" id="password-error">{{ $errors['password'][0] }}</span>
                    @else
                        <small id="password-hint">至少 6 个字符。</small>
                    @endif
                </div>
            </div>
            <button type="submit">开始安装</button>
        </form>
    @endif
</main>
</body>
</html>
