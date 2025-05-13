<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>
        @include('notifications.FEEDBACK.ORDER_QUESTION.webform.author.email.en.subject')
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
        Your transaction question has been registered.
    </h4>
    <div>
        <span class="key">To view the discussion, follow the link:</span>
        <span class="value"><a href="{{ sroute('feedback.detail', [$discussion->getId()], true) }}">link</a></span>
    </div>

</body>
</html>
