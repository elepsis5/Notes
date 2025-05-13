<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>
        @include('notifications.CUSTOM.PRODUCT_INSTOCK.webform.responsibles.email.ru.subject')
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
    @if(!is_null($errorMessage))
    <div class="error_message">{{ $errorMessage }}</div>
    @endif
    <div>
        <span class="key">Сделка:</span>
        <span class="value"><a href="{{ sroute('orders.show', [$assignedObject->group], true, ['order_id'=>$assignedObject->uid])}}" target="_blank" > {{ $assignedObject->order_link_1c ?? ''}} </a></span>
    </div>
    @foreach (json_decode($values['instock_info']) as $produtcode =>  $quantity)
    <div>
        На склад прибыл товар <span class="key">{{ $produtcode }}: </span>
        <span class="value">в количестве {{ $quantity }} шт.</span>
    </div>

    @endforeach
    <div>
        <span class="key">Перейти к обращению:</span>
        <span class="value"><a href="{{ sroute('feedback.detail', [$discussion->getId()], true) }}">ссылка</a></span>
    </div>



</body>
</html>
