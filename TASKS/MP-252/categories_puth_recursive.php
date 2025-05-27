protected function sandbox(Request $request)
    {
        
        $user = user();

        $supplier = Supplier::findByUser($user);
        $id = '6826fc41132767063e05fdfa';
        $result = Category::query()->where('supplier', '80095')->get();

        $childs = $result->where('parent_id', $id)->pluck('id');

        $puths = [];
        $foo = function($id, $collect, $path = '') use (&$foo, &$puths) {
            $path .= $id . '/';
            $childs = $collect->where('parent_id', $id);
            if ($childs->count() > 0) {
                foreach($childs as $child) {
    
                    $foo($child->id, $collect, $path);
                }
            } else {
                $puths[] = $path;
                return false;
            }

        };
        $foo($id, $result);

        $namesPuths = [];
        $bar = function($id, $collect, $path = '') use (&$bar, &$namesPuths) {
            $item = $collect->where('id', $id)->first();
            $path .= $item->name . '/';
            $childs = $collect->where('parent_id', $id);
            if ($childs->count() > 0) {
                foreach($childs as $child) {
    
                    $bar($child->id, $collect, $path);
                }
            } else {
                $namesPuths[] = $path;
                return false;
            }

        };
        $bar($id, $result);




        $categoryMap = [];
        foreach ($result as $category) {
            $categoryMap[$category['_id']] = $category;
        }
        $categories = collect();
        
        foreach ($result as $category) {
            $level = 0;
            $currentCategory = $category;
            $path = $category->name;
            while ($currentCategory['parent_id'] != '0' && isset($categoryMap[$currentCategory['parent_id']])) {
                $level++;
                $currentCategory = $categoryMap[$currentCategory['parent_id']];
                $path = $currentCategory->name.'/'.$path;
            }
            $category->path = $path;
            $category->level = $level;
            $categories->push($category);
        }
        $categories = $categories->sortBy('path');
        dd($childs, $puths, $namesPuths, $categories->pluck('path'));
    }