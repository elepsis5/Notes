<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>
        @include('notifications.CUSTOM.PRODUCT_INSTOCK.webform.author.email.ru.subject')
    </title>
    <style>
        body{
            padding: 0.5em;
            line-height: 1.5em;
        }
        .header{
            font-weight: bold;
            font-size: 1.2em;
            background-color: #ccffcc;
            padding: 1em;
        }
        .error_message {
            font-weight: bold;
            background-color: red;
            color: white;
            padding: 1em;
            margin-bottom: 1em;
        }
        .key{
            font-weight: bold;
        }
        .value{
            font-style: italic;
        }
        .conclusion{
            margin-top: 2em;
        }
    </style>
</head>
<body>
    <h4 class="header">
        Ваш вопрос по сделке зарегистрирован.
    </h4>
    <div>
        <span class="key">Для просмотра обращения перейдите по ссылке:</span>
        <span class="value"><a href="{{ sroute('feedback.detail', [$discussion->getId()], true) }}">ссылка</a></span>
    </div>

</body>
</html>
