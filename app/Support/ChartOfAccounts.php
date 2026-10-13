<?php

namespace App\Support;

/**
 * Azərbaycan Respublikasında kommersiya təşkilatları üçün Mühasibat uçotu hesablarının Hesablar Planı
 * (milli mühasibat uçotu standartları əsasında; bölmə → maddə → hesab) və balansdankənar hesablar.
 * Qeyd: hesab adları və nömrələri sistemdə redaktə oluna bilər — mühasibinizlə tutuşdurun.
 *
 * Xarakter: A — aktiv, P — passiv, K — kontr-aktiv (azaldıcı), G — gəlir, X — xərc, N — maliyyə nəticəsi,
 * B — balansdankənar.
 */
class ChartOfAccounts
{
    /**
     * @return array<string, string> bölmə nömrəsi => ad
     */
    public static function sections(): array
    {
        return [
            '1' => __('Uzunmüddətli aktivlər'),
            '2' => __('Qısamüddətli aktivlər'),
            '3' => __('Kapital'),
            '4' => __('Uzunmüddətli öhdəliklər'),
            '5' => __('Qısamüddətli öhdəliklər'),
            '6' => __('Gəlirlər'),
            '7' => __('Xərclər'),
            '8' => __('Mənfəətlər (zərərlər)'),
            '9' => __('Mənfəət vergisi'),
            '0' => __('Balansdankənar hesablar'),
        ];
    }

    /**
     * @return array<string, string> maddə (2 rəqəm) => ad
     */
    public static function groups(): array
    {
        return [
            '10' => __('Qeyri-maddi aktivlər'),
            '11' => __('Torpaq, tikili və avadanlıqlar'),
            '12' => __('Daşınmaz əmlaka investisiyalar'),
            '13' => __('Bioloji aktivlər'),
            '14' => __('Təbii sərvətlər (ehtiyatlar)'),
            '15' => __('İştirak payı metodu ilə uçota alınmış investisiyalar'),
            '16' => __('Təxirə salınmış vergi aktivləri'),
            '17' => __('Uzunmüddətli debitor borcları'),
            '18' => __('Sair uzunmüddətli maliyyə aktivləri'),
            '19' => __('Sair uzunmüddətli aktivlər'),
            '20' => __('Ehtiyatlar'),
            '21' => __('Qısamüddətli debitor borcları'),
            '22' => __('Pul vəsaitləri və onların ekvivalentləri'),
            '23' => __('Qısamüddətli investisiyalar'),
            '24' => __('Sair qısamüddətli aktivlər'),
            '30' => __('Ödənilmiş nominal (nizamnamə) kapital'),
            '31' => __('Emissiya gəliri'),
            '32' => __('Geri alınmış kapital (səhmlər)'),
            '33' => __('Kapital ehtiyatları'),
            '34' => __('Bölüşdürülməmiş mənfəət (ödənilməmiş zərər)'),
            '40' => __('Uzunmüddətli faiz xərcləri yaradan öhdəliklər'),
            '41' => __('Uzunmüddətli qiymətləndirilmiş öhdəliklər'),
            '42' => __('Təxirə salınmış vergi öhdəlikləri'),
            '43' => __('Uzunmüddətli kreditor borcları'),
            '44' => __('Sair uzunmüddətli öhdəliklər'),
            '50' => __('Qısamüddətli faiz xərcləri yaradan öhdəliklər'),
            '51' => __('Qısamüddətli qiymətləndirilmiş öhdəliklər'),
            '52' => __('Vergi və sair məcburi ödənişlər üzrə öhdəliklər'),
            '53' => __('Qısamüddətli kreditor borcları'),
            '54' => __('Sair qısamüddətli öhdəliklər'),
            '60' => __('Əsas əməliyyat gəliri'),
            '61' => __('Sair əməliyyat gəlirləri'),
            '62' => __('Fəaliyyətin dayandırılmasından gəlirlər'),
            '63' => __('Maliyyə gəlirləri'),
            '64' => __('Fövqəladə gəlirlər'),
            '70' => __('Satışın maya dəyəri'),
            '71' => __('Kommersiya xərcləri'),
            '72' => __('İnzibati xərclər'),
            '73' => __('Sair əməliyyat xərcləri'),
            '74' => __('Fəaliyyətin dayandırılmasından xərclər'),
            '75' => __('Maliyyə xərcləri'),
            '76' => __('Fövqəladə xərclər'),
            '80' => __('Ümumi mənfəət (zərər)'),
            '81' => __('Asılı və birgə müəssisələrin mənfəətlərində (zərərlərində) pay'),
            '90' => __('Mənfəət vergisi'),
            '00' => __('Balansdankənar hesablar'),
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> [hesab, ad, xarakter]
     */
    public static function accounts(): array
    {
        return [
            ['101', 'Qeyri-maddi aktivlərin dəyəri', 'A'],
            ['102', 'Qeyri-maddi aktivlər üzrə yığılmış amortizasiya', 'K'],
            ['103', 'Qeyri-maddi aktivlərlə bağlı məsrəflərin kapitallaşdırılması', 'A'],
            ['111', 'Torpaq, tikili və avadanlıqların dəyəri', 'A'],
            ['112', 'Torpaq, tikili və avadanlıqlar üzrə yığılmış amortizasiya', 'K'],
            ['113', 'Torpaq, tikili və avadanlıqlarla bağlı məsrəflərin kapitallaşdırılması', 'A'],
            ['121', 'Daşınmaz əmlaka investisiyaların dəyəri', 'A'],
            ['122', 'Daşınmaz əmlaka investisiyalar üzrə yığılmış amortizasiya', 'K'],
            ['123', 'Daşınmaz əmlaka investisiyalarla bağlı məsrəflərin kapitallaşdırılması', 'A'],
            ['131', 'Bioloji aktivlərin dəyəri', 'A'],
            ['132', 'Bioloji aktivlər üzrə yığılmış amortizasiya və qiymətdən düşmə zərərləri', 'K'],
            ['141', 'Təbii sərvətlərin (ehtiyatların) dəyəri', 'A'],
            ['142', 'Təbii sərvətlərin (ehtiyatların) tükənməsi', 'K'],
            ['151', 'Asılı müəssisələrə investisiyalar', 'A'],
            ['152', 'Birgə müəssisələrə investisiyalar', 'A'],
            ['153', 'İştirak payı metodu ilə uçota alınmış investisiyaların dəyərinin azalmasına görə düzəlişlər', 'K'],
            ['161', 'Mənfəət vergisi üzrə təxirə salınmış vergi aktivləri', 'A'],
            ['162', 'Digər təxirə salınmış vergi aktivləri', 'A'],
            ['171', 'Alıcılar və sifarişçilərin uzunmüddətli debitor borcları', 'A'],
            ['172', 'Törəmə (asılı) müəssisələrin uzunmüddətli debitor borcları', 'A'],
            ['173', 'Əsas idarəetmə heyətinin uzunmüddətli debitor borcları', 'A'],
            ['174', 'İcarə üzrə uzunmüddətli debitor borcları', 'A'],
            ['175', 'Tikinti müqavilələri üzrə uzunmüddətli debitor borcları', 'A'],
            ['176', 'Faizlər üzrə uzunmüddətli debitor borcları', 'A'],
            ['177', 'Digər uzunmüddətli debitor borcları', 'A'],
            ['181', 'Ödənişə qədər saxlanılan uzunmüddətli investisiyalar', 'A'],
            ['182', 'Verilmiş uzunmüddətli borclar', 'A'],
            ['183', 'Digər uzunmüddətli investisiyalar', 'A'],
            ['184', 'Sair uzunmüddətli maliyyə aktivlərinin dəyərinin azalmasına görə düzəlişlər', 'K'],
            ['191', 'Gələcək hesabat dövrlərinin xərcləri (uzunmüddətli)', 'A'],
            ['192', 'Verilmiş uzunmüddətli avanslar', 'A'],
            ['193', 'Digər uzunmüddətli aktivlər', 'A'],
            ['201', 'Material ehtiyatları', 'A'],
            ['202', 'İstehsalat (iş və xidmət) məsrəfləri', 'A'],
            ['203', 'Tikinti müqavilələri üzrə çəkilən məsrəflər', 'A'],
            ['204', 'Hazır məhsul', 'A'],
            ['205', 'Mallar', 'A'],
            ['206', 'Satış məqsədi ilə saxlanılan digər aktivlər', 'A'],
            ['207', 'Digər ehtiyatlar', 'A'],
            ['208', 'Ehtiyatların dəyərinin azalmasına görə düzəlişlər', 'K'],
            ['211', 'Alıcılar və sifarişçilərin qısamüddətli debitor borcları', 'A'],
            ['212', 'Törəmə (asılı) müəssisələrin qısamüddətli debitor borcları', 'A'],
            ['213', 'Əsas idarəetmə heyətinin qısamüddətli debitor borcları', 'A'],
            ['214', 'İcarə üzrə qısamüddətli debitor borcları', 'A'],
            ['215', 'Tikinti müqavilələri üzrə qısamüddətli debitor borcları', 'A'],
            ['216', 'Faizlər üzrə qısamüddətli debitor borcları', 'A'],
            ['217', 'Digər qısamüddətli debitor borcları', 'A'],
            ['218', 'Şübhəli borclar üzrə düzəlişlər', 'K'],
            ['221', 'Kassa', 'A'],
            ['222', 'Yolda olan pul köçürmələri', 'A'],
            ['223', 'Bank hesablaşma hesabları', 'A'],
            ['224', 'Tələblərə əsasən açılan digər bank hesabları', 'A'],
            ['225', 'Pul vəsaitlərinin ekvivalentləri', 'A'],
            ['231', 'Satış məqsədi ilə saxlanılan qısamüddətli investisiyalar', 'A'],
            ['232', 'Ödənişə qədər saxlanılan qısamüddətli investisiyalar', 'A'],
            ['233', 'Verilmiş qısamüddətli borclar', 'A'],
            ['234', 'Digər qısamüddətli investisiyalar', 'A'],
            ['235', 'Qısamüddətli investisiyaların dəyərinin azalmasına görə düzəlişlər', 'K'],
            ['241', 'Əvəzləşdirilən vergilər', 'A'],
            ['242', 'Gələcək hesabat dövrlərinin xərcləri', 'A'],
            ['243', 'Verilmiş qısamüddətli avanslar', 'A'],
            ['244', 'Təhtəlhesab məbləğlər', 'A'],
            ['245', 'Digər qısamüddətli aktivlər', 'A'],
            ['301', 'Nominal (nizamnamə) kapital', 'P'],
            ['302', 'Nominal (nizamnamə) kapitalın ödənilməmiş hissəsi', 'K'],
            ['311', 'Emissiya gəliri', 'P'],
            ['321', 'Geri alınmış kapital (səhmlər)', 'K'],
            ['331', 'Yenidən qiymətləndirilmə üzrə ehtiyat', 'P'],
            ['332', 'Məzənnə fərgləri üzrə ehtiyat', 'P'],
            ['333', 'Qanunvericilik üzrə ehtiyat', 'P'],
            ['334', 'Nizamnamə üzrə ehtiyat', 'P'],
            ['335', 'Digər ehtiyatlar', 'P'],
            ['341', 'Hesabat dövründə xalis mənfəət (zərər)', 'P'],
            ['342', 'Mühasibat uçotu siyasətində dəyişikliklərlə bağlı mənfəət (zərər) üzrə düzəlişlər', 'P'],
            ['343', 'Keçmiş illər üzrə bölüşdürülməmiş mənfəət (ödənilməmiş zərər)', 'P'],
            ['344', 'Elan edilmiş dividendlər', 'P'],
            ['401', 'Uzunmüddətli bank kreditləri', 'P'],
            ['402', 'İşçilər üçün uzunmüddətli bank kreditləri', 'P'],
            ['403', 'Uzunmüddətli konvertasiya olunan istiqrazlar', 'P'],
            ['404', 'Uzunmüddətli borclar', 'P'],
            ['405', 'Geri alınan məhdud tədavül müddətli imtiyazlı səhmlər (uzunmüddətli)', 'P'],
            ['406', 'Maliyyə icarəsi üzrə uzunmüddətli öhdəliklər', 'P'],
            ['407', 'Törəmə (asılı) müəssisələrə uzunmüddətli borclar', 'P'],
            ['408', 'Digər uzunmüddətli faiz xərcləri yaradan öhdəliklər', 'P'],
            ['411', 'İşdən azad olma ilə bağlı uzunmüddətli müavinətlər və öhdəliklər', 'P'],
            ['412', 'Uzunmüddətli zəmanət öhdəlikləri', 'P'],
            ['413', 'Uzunmüddətli hüquqi öhdəliklər', 'P'],
            ['414', 'Digər uzunmüddətli qiymətləndirilmiş öhdəliklər', 'P'],
            ['421', 'Mənfəət vergisi üzrə təxirə salınmış vergi öhdəlikləri', 'P'],
            ['422', 'Digər təxirə salınmış vergi öhdəlikləri', 'P'],
            ['431', 'Malsatan və podratçılara uzunmüddətli kreditor borcları', 'P'],
            ['432', 'Törəmə (asılı) müəssisələrə uzunmüddətli kreditor borcları', 'P'],
            ['433', 'Təsisçilərə uzunmüddətli kreditor borcları', 'P'],
            ['434', 'Digər uzunmüddətli kreditor borcları', 'P'],
            ['441', 'Uzunmüddətli təxirə salınmış gəlirlər', 'P'],
            ['442', 'Uzunmüddətli alınmış avanslar', 'P'],
            ['443', 'Uzunmüddətli məqsədli maliyyələşmələr və daxilolmalar', 'P'],
            ['444', 'Digər uzunmüddətli öhdəliklər', 'P'],
            ['501', 'Qısamüddətli bank kreditləri', 'P'],
            ['502', 'İşçilər üçün qısamüddətli bank kreditləri', 'P'],
            ['503', 'Qısamüddətli konvertasiya olunan istiqrazlar', 'P'],
            ['504', 'Qısamüddətli borclar', 'P'],
            ['505', 'Geri alınan məhdud tədavül müddətli imtiyazlı səhmlər (qısamüddətli)', 'P'],
            ['506', 'Törəmə (asılı) müəssisələrə qısamüddətli borclar', 'P'],
            ['507', 'Digər qısamüddətli faiz xərcləri yaradan öhdəliklər', 'P'],
            ['511', 'İşdən azad olma ilə bağlı qısamüddətli müavinətlər və öhdəliklər', 'P'],
            ['512', 'Qısamüddətli zəmanət öhdəlikləri', 'P'],
            ['513', 'Qısamüddətli hüquqi öhdəliklər', 'P'],
            ['514', 'Mənfəətdə iştirak planı və müavinət planları üzrə öhdəliklər', 'P'],
            ['515', 'Digər qısamüddətli qiymətləndirilmiş öhdəliklər', 'P'],
            ['521', 'Vergi öhdəlikləri', 'P'],
            ['522', 'Sosial sığorta və təminat üzrə öhdəliklər', 'P'],
            ['523', 'Digər məcburi ödənişlər üzrə öhdəliklər', 'P'],
            ['531', 'Malsatan və podratçılara qısamüddətli kreditor borcları', 'P'],
            ['532', 'Törəmə (asılı) müəssisələrə qısamüddətli kreditor borcları', 'P'],
            ['533', 'Əməyin ödənişi üzrə işçi heyətinə olan borclar', 'P'],
            ['534', 'Dividendlərin ödənilməsi üzrə təsisçilərə kreditor borcları', 'P'],
            ['535', 'İcarə üzrə qısamüddətli kreditor borcları', 'P'],
            ['536', 'Tikinti müqavilələri üzrə qısamüddətli kreditor borcları', 'P'],
            ['537', 'Faizlər üzrə kreditor borcları', 'P'],
            ['538', 'Digər qısamüddətli kreditor borcları', 'P'],
            ['541', 'Qısamüddətli təxirə salınmış gəlirlər', 'P'],
            ['542', 'Alınmış qısamüddətli avanslar', 'P'],
            ['543', 'Qısamüddətli məqsədli maliyyələşmələr və daxilolmalar', 'P'],
            ['544', 'Digər qısamüddətli öhdəliklər', 'P'],
            ['601', 'Satış', 'G'],
            ['602', 'Satılmış malların qaytarılması və ucuzlaşdırılması', 'G'],
            ['603', 'Verilmiş güzəştlər', 'G'],
            ['611', 'Sair əməliyyat gəlirləri', 'G'],
            ['621', 'Fəaliyyətin dayandırılmasından gəlirlər', 'G'],
            ['631', 'Maliyyə gəlirləri', 'G'],
            ['641', 'Fövqəladə gəlirlər', 'G'],
            ['701', 'Satışın maya dəyəri', 'X'],
            ['711', 'Kommersiya xərcləri', 'X'],
            ['721', 'İnzibati xərclər', 'X'],
            ['731', 'Sair əməliyyat xərcləri', 'X'],
            ['741', 'Fəaliyyətin dayandırılmasından xərclər', 'X'],
            ['751', 'Maliyyə xərcləri', 'X'],
            ['761', 'Fövqəladə xərclər', 'X'],
            ['801', 'Ümumi mənfəət (zərər)', 'N'],
            ['811', 'Asılı və birgə müəssisələrin mənfəətlərində (zərərlərində) pay', 'N'],
            ['901', 'Cari mənfəət vergisi üzrə xərclər', 'X'],
            ['902', 'Təxirə salınmış mənfəət vergisi üzrə xərclər', 'X'],
            ['001', 'İcarəyə götürülmüş əsas vəsaitlər', 'B'],
            ['002', 'Məsuliyyətli mühafizəyə götürülmüş inventar və materiallar', 'B'],
            ['003', 'Emal üçün qəbul edilmiş materiallar', 'B'],
            ['004', 'Komisyona qəbul edilmiş mallar', 'B'],
            ['005', 'Quraşdırılma üçün qəbul edilmiş avadanlıq', 'B'],
            ['006', 'Ciddi hesabat blankları', 'B'],
            ['007', 'Ödəmə qabiliyyəti olmayan debitorların zərərə silinmiş borcları', 'B'],
            ['008', 'Alınmış öhdəliklərin və ödənişlərin təminatı', 'B'],
            ['009', 'Verilmiş öhdəliklərin və ödənişlərin təminatı', 'B'],
        ];
    }

    /**
     * Köhnə (1C / sovet tipli) hesab nömrəsi → yeni hesab. Balans faylındakı köhnə nömrələri yeni plana bağlayır.
     *
     * @return array<string, array{0: string, 1: string}> köhnə => [yeni, qeyd]
     */
    public static function legacyMap(): array
    {
        return [
            '1' => ['111', ''],
            '2' => ['112', ''],
            '3' => ['111', 'Uzunmüddətə icarəyə götürülmüş əsas vəsaitlər (maliyyə icarəsi)'],
            '4' => ['101', ''],
            '5' => ['102', ''],
            '8' => ['113', ''],
            '10' => ['201', ''],
            '12' => ['201', 'Azqiymətli və tezköhnələn əşyalar material ehtiyatlarına daxildir'],
            '13' => ['208', 'Azqiymətli əşyaların köhnəlməsi — ehtiyatları azaldır'],
            '19' => ['241', 'Alınmış sərvətlər üzrə ƏDV — əvəzləşdirilən vergi'],
            '41' => ['205', ''],
            '50' => ['221', ''],
            '51' => ['223', ''],
            '52' => ['223', 'Valyuta hesabı — bank hesablaşma hesabları'],
            '55' => ['224', ''],
            '58' => ['234', ''],
            '61' => ['243', ''],
            '62' => ['211', ''],
            '71' => ['244', ''],
            '60' => ['531', ''],
            '64' => ['542', ''],
            '68' => ['521', ''],
            '69' => ['522', ''],
            '70' => ['533', ''],
            '75' => ['534', ''],
            '76' => ['538', 'Müxtəlif debitor və kreditorlar; təsisçi borcu müqaviləsi üzrə məbləğ 433 / 504 hesabında da uçota alına bilər'],
            '78' => ['532', ''],
            '79' => ['538', ''],
            '80' => ['341', 'Hesabat dövrünün mənfəəti (zərəri)'],
            '83' => ['541', ''],
            '85' => ['301', ''],
            '87' => ['335', 'Əlavə kapital'],
            '88' => ['343', ''],
        ];
    }

    public static function groupOf(string $code): string
    {
        return substr($code, 0, 2);
    }

    public static function sectionOf(string $code): string
    {
        return substr($code, 0, 1);
    }
}
