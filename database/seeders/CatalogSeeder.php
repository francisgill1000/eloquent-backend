<?php

namespace Database\Seeders;

use App\Models\Catalog;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['title' => "Signature Haircut", 'description' => "45 min • Styling included", 'price' => 65.00, 'image' => "https://lh3.googleusercontent.com/aida-public/AB6AXuCwMyLE2JRqV7y0BpYEYbQcQah_0DgDIsLzauhrmwnF3oi0hbL8kA-wOpdBF82QJH545A5M04QcxhC80SA9uV0Z_dVtZccVWHNxejHsMd9KFKTnqxuch9bp3sW75StvWF7zj7Ydqx8Vu4qNGblpC4ofr0_EZzkvWvhbuH4aQcF03dfgHKXX47BIvJOQGzpOX80XfOnYfvYUi56EzyRqcaKqgO2Hbt81fSNBSHjtqkQanwVsS2hh9RccruEXiDX8JTZqFZqnz3G1BpJb"],
            ['title' => "Royal Beard Groom", 'description' => "30 min • Hot towel", 'price' => 45.00, 'image' => "https://lh3.googleusercontent.com/aida-public/AB6AXuAa2HGA6r5474ZBXmKPXjMczhQrzc0WaFghXQttvrzDPNJ701GvCj8MbghytS2tKjUCPwce_dGPTsqOxA1fXFF_qz2731zqiu0mq53gbXq6jPTp8paFv83bUXTUR3QtlSFomU43Zk52rxWGGLDPXuNDBKMnSqeqcRlFFn5Ge8zpuHytIh_6GQJLIllHH_tKuTcvvhiCk42fUIQmKKFh2KuLm_gjU_mTsym5C41f0TSHAAGj_AuUcWjaMLBtazyLQu26AZKpbrLWhb1F"],
            ['title' => "Scalp Therapy", 'description' => "20 min • Organic oils", 'price' => 35.00, 'image' => "https://lh3.googleusercontent.com/aida-public/AB6AXuAlTQ95_yiXLiVs34q-yclG8hFqgraZUKbyPzaOGkI7PmLW0_eaMzTyhcuQA3w3NtybTAAv7SUJxCTaBkhA_nI9-M_mJQTUKNHeuaOmGaZQDaHwSXC1fyy4GR6MrFCABonp5GSFFikd6mW2LGD6ssP8FNrgoYdFUL5diiF9aX5oHPba8Nfyn9VZuihwOVgQScB-up3qnm_36_06sUuhbn3himOhsw2fz_xA4fVyt072FnjRJ5ZDWmsRbel6cIK6oShX8hpMssawSTZL"],
        ];

        DB::table('catalogs')->insert($items);
    }
}
