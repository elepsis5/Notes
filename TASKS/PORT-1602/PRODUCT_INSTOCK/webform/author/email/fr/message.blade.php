<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>
        @include('notifications.FEEDBACK.ORDER_QUESTION.webform.author.email.fr.subject')
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
        Votre question de transaction a été enregistrée.
    </h4>
    <div>
        <span class="key">Pour consulter l appel, suivez le lien:</span>
        <span class="value"><a href="{{ sroute('feedback.detail', [$discussion->getId()], true) }}">lien</a></span>
    </div>

</body>
</html>
