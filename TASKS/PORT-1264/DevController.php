    /**
     * Выводит переводы из JS файлов
     *
     * @return view
     */
    protected function js_translation()
    {
        $it = new \RecursiveDirectoryIterator(env('DOCUMENT_ROOT'));
        $allowed= ["ru.js","en.js", "fr.js"];
        $jsLang = [];
        foreach(new \RecursiveIteratorIterator($it) as $file) {
            if(in_array(basename($file) ,$allowed)) {
                if(basename($file) == 'ru.js')  $jsLangRu[] = str_replace(env('DOCUMENT_ROOT'), '', $file);
                if(basename($file) == 'en.js')  $jsLangEn[] = str_replace(env('DOCUMENT_ROOT'), '', $file);
                if(basename($file) == 'fr.js')  $jsLangFr[] = str_replace(env('DOCUMENT_ROOT'), '', $file);
            }
        }

        return view('dev.jsLang', [
            'jsLangRu' => $jsLangRu,
            'jsLangEn' => $jsLangEn,
            'jsLangFr' => $jsLangFr
        ]);

    }