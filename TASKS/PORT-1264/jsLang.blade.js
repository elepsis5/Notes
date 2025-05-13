@extends('layouts.master')
@section('scripts')
    @foreach($jsLangRu as $lang)
            <script src="{{ $lang }}"></script>
    @endforeach
    <script>
            function htmlEntities(str) {
                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            var TranslationsRu = [];
            for (const [ObjKey, Objvalue] of Object.entries(window.translate_global)) {
                tempKey = [];
                for (const [SubObjKey, SubObjvalue] of Object.entries(Objvalue)) {
                    tempKey[SubObjKey] = {'RU':SubObjvalue};
                }
                TranslationsRu[ObjKey] = tempKey;
            }
            console.log(TranslationsRu);
    </script>
    @foreach($jsLangEn as $lang)
            <script src="{{ $lang }}"></script>
    @endforeach
    <script>
            var TranslationsEn = [];
            for (const [ObjKey, Objvalue] of Object.entries(window.translate_global)) {
                tempKey = [];
                for (const [SubObjKey, SubObjvalue] of Object.entries(Objvalue)) {
                    tempKey[SubObjKey] = {'EN':SubObjvalue};
                }
                TranslationsEn[ObjKey] = tempKey;
            }
            //console.log(TranslationsEn);

            // var ResultTranslations = [];
            // for (const [ObjKey, Objvalue] of Object.entries(TranslationsRu)) {
            //     tempKey = [];
            //     for (const [SubObjKey, SubObjvalue] of Object.entries(Objvalue)) {
            //         tempKey[SubObjKey] = {'RU':SubObjvalue['RU'], 'EN': TranslationsEn[ObjKey][SubObjKey]['EN']};
            //     }
            //     ResultTranslations[ObjKey] = tempKey;
            // }

            // var ResultTranslationsTable = '<table class="table table-bordered "><tr><td>OBJECT</td><td>KEY</td><td>RU</td><td>EN</td></tr>';
            // for (const [ObjKey, Objvalue] of Object.entries(TranslationsRu)) {
            //     for (const [SubObjKey, SubObjvalue] of Object.entries(Objvalue)) {
            //         ResultTranslationsTable += '<tr><td>' + ObjKey + '</td>';
            //         ResultTranslationsTable += '<td>' + SubObjKey + '</td>';
            //         ResultTranslationsTable += '<td>' + htmlEntities(SubObjvalue['RU']) + '</td>';
            //         ResultTranslationsTable += '<td>' + htmlEntities(TranslationsEn[ObjKey][SubObjKey]['EN']) + '</td>';
            //         ResultTranslationsTable += '</tr>';
            //     }
            // }
            // //console.log(ResultTranslationsTable);

            // document.getElementById('results').insertAdjacentHTML('afterbegin', ResultTranslationsTable);
    </script>
    </script>
    @foreach($jsLangFr as $lang)
            <script src="{{ $lang }}"></script>
    @endforeach
    <script>
            var TranslationsFr = [];
            for (const [ObjKey, Objvalue] of Object.entries(window.translate_global)) {
                tempKey = [];
                for (const [SubObjKey, SubObjvalue] of Object.entries(Objvalue)) {
                    tempKey[SubObjKey] = {'FR':SubObjvalue};
                }
                TranslationsFr[ObjKey] = tempKey;
            }

            var ResultTranslationsTable = '<table class="table table-bordered "><tr><td>OBJECT</td><td>KEY</td><td>RU</td><td>EN</td><td>FR</td></tr>';
            for (const [ObjKey, Objvalue] of Object.entries(TranslationsRu)) {
                for (const [SubObjKey, SubObjvalue] of Object.entries(Objvalue)) {
                    ResultTranslationsTable += '<tr><td>' + ObjKey + '</td>';
                    ResultTranslationsTable += '<td>' + SubObjKey + '</td>';
                    ResultTranslationsTable += '<td>' + htmlEntities(SubObjvalue['RU']) + '</td>';
                    ResultTranslationsTable += '<td>' + htmlEntities(TranslationsEn[ObjKey][SubObjKey]['EN']) + '</td>';
                    ResultTranslationsTable += '<td>' + htmlEntities(TranslationsFr[ObjKey][SubObjKey]['FR']) + '</td>';
                    ResultTranslationsTable += '</tr>';
                }
            }
            //console.log(ResultTranslationsTable);

            document.getElementById('results').insertAdjacentHTML('afterbegin', ResultTranslationsTable);
    </script>
@endsection
@section('content')
    <div id="results" style="padding: 30px; background: #fff;"></div>
@endsection
