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
        fieldset { border: 0; border-top: 1px solid #e2eaec; margin: 0 0 20px; padding: 14px 0 0; min-width: 0; }
        legend { font-size: 17px; font-weight: 700; padding-right: 10px; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 18px; }
        .wide { grid-column: 1 / -1; }
        label { display: block; font-weight: 600; margin-bottom: 4px; }
        input { width: 100%; border: 1px solid #aabac2; border-radius: 7px; min-height: 43px; padding: 8px 11px; font: inherit; color: inherit; }
        [aria-invalid=true] { border-color: #a52d25; }
        input:focus-visible, button:focus-visible, a:focus-visible { outline: 3px solid #268d70; outline-offset: 2px; }
        .error { display: block; color: #a52d25; margin-top: 4px; }
        .notice { padding: 12px 16px; background: #fff1ed; color: #842a20; border-radius: 8px; margin-bottom: 20px; }
        button, .button { display: inline-block; background: #176950; color: #fff; border: 0; border-radius: 8px; padding: 10px 20px; font: inherit; font-weight: 700; cursor: pointer; text-decoration: none; }
        button:hover, .button:hover { background: #104f3d; }
        @media (max-width: 540px) { main { margin: 18px auto; } .fields { grid-template-columns: 1fr; } }
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
        <p class="description">配置网站与 MySQL 数据库，并创建首个运营后台账号。</p>

        @if (isset($errors['install']))
            <p class="notice" role="alert">{{ $errors['install'][0] }}</p>
        @elseif ($errors !== [])
            <p class="notice" role="alert">请检查标记的字段，然后重新输入密码。</p>
        @endif

        <form method="post" action="{{ route('site-install.store', absolute: false) }}">
            <input type="hidden" name="_token" value="{{ $csrf }}">
            @php
                $sections = [
                    '网站设置' => [
                        ['app_url', '网站地址', 'url', '', 'url', true],
                    ],
                    'MySQL 数据库' => [
                        ['db_host', '主机地址', 'text', '127.0.0.1', 'off', false],
                        ['db_port', '端口', 'number', '3306', 'off', false],
                        ['db_name', '数据库名称', 'text', '', 'off', false],
                        ['db_user', '数据库用户名', 'text', '', 'off', false],
                        ['db_password', '数据库密码', 'password', '', 'new-password', true],
                    ],
                    '后台账号' => [
                        ['username', '账号', 'text', '', 'username', false],
                        ['password', '密码', 'password', '', 'new-password', false],
                    ],
                ];
            @endphp
            @foreach ($sections as $legend => $fields)
                <fieldset>
                    <legend>{{ $legend }}</legend>
                    <div class="fields">
                        @foreach ($fields as [$name, $label, $type, $default, $autocomplete, $wide])
                            @php
                                $error = $errors[$name][0] ?? null;
                                $value = $values[$name] ?? $default;
                            @endphp
                            <div @class(['wide' => $wide])>
                                <label for="{{ $name }}">{{ $label }}</label>
                                <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}"
                                       @if ($type !== 'password') value="{{ is_scalar($value) ? $value : '' }}" @endif
                                       required
                                       @if ($name === 'db_port') min="1" max="65535" @endif
                                       @if ($name === 'username') maxlength="64" @endif
                                       @if ($name === 'password') minlength="5" @endif
                                       @if ($error) aria-invalid="true" aria-describedby="{{ $name }}-error"
                                       @elseif ($name === 'password') aria-describedby="password-hint" @endif>
                                @if ($error)
                                    <span class="error" id="{{ $name }}-error">{{ $error }}</span>
                                @elseif ($name === 'password')
                                    <small id="password-hint">至少 5 个字符，可使用纯数字。</small>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
            <button type="submit">开始安装</button>
        </form>
    @endif
</main>
</body>
</html>
