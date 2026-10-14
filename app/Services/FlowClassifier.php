<?php

namespace App\Services;

use App\Support\NameNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Cash Flow (bank / mühasibat) sətirlərinin təsnifatı: hansı ödəniş əməliyyat xərcidir, hansı deyil.
 *
 * Qayda: əvvəlcə istifadəçinin əl ilə seçimi (flow_classifications), sonra aşağıdakı qaydalar.
 * P&L-ə yalnız «expense» növü düşür. Mal alışı və gömrük maya dəyərinə (anbar modulu), ƏDV / vergi,
 * təsisçi borcu, əsas vəsait alışı və müştəriyə qaytarma xərc sayılmır. Tanınmayan ödəniş P&L-ə düşmür.
 */
class FlowClassifier
{
    public const TYPES = ['expense', 'cogs', 'tax', 'financing', 'capex', 'refund', 'revenue', 'fx', 'uncertain'];

    /** Yalnız bu növ mənfəət-zərərə əməliyyat xərci kimi düşür. */
    public const EXPENSE = 'expense';

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            'expense' => __('Əməliyyat xərci'),
            'cogs' => __('Mal alışı / gömrük (maya dəyəri)'),
            'tax' => __('Vergi (xərc deyil)'),
            'financing' => __('Maliyyələşmə (təsisçi borcu)'),
            'capex' => __('Əsas vəsait alışı'),
            'refund' => __('Müştəriyə geri ödəniş'),
            'revenue' => __('Satış gəliri'),
            'fx' => __('Valyuta yenidən qiymətləndirməsi'),
            'uncertain' => __('Təsnif olunmayıb'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function expenseGroups(): array
    {
        return [
            'Əmək haqqı', 'Sosial sığorta (DSMF)', 'İcbari tibbi sığorta', 'İcarə', 'Nəqliyyat və logistika', 'Yanacaq',
            'Rabitə', 'IT və proqram təminatı', 'Reklam və marketinq', 'Audit, mühasibat və məsləhət', 'Sığorta', 'Ezamiyyə',
            'Bank xidməti', 'Maliyyə xərcləri (kurs fərqi)', 'Cərimə və sanksiyalar', 'Ofis və təsərrüfat',
            'Fiziki şəxslərə xidmət haqqı', 'Sair xərclər',
        ];
    }

    /**
     * Bank ödənişinin P&L-dəki «Rəsmi xərclər» qrupu. Mühasibat faylındakı bütün ödənişlər rəsmi xərc kimi göstərilir;
     * yalnız mal alışı / gömrük çıxılır — onlar satılan malın mayasında artıq var (ikinci dəfə sayılmasın).
     *
     * @param  array{type: string, group: string|null}  $classification
     */
    public static function pnlGroup(array $classification): ?string
    {
        return match ($classification['type']) {
            'cogs' => null,
            self::EXPENSE, 'revenue' => $classification['group'] ?: 'Sair xərclər',
            'tax' => 'Vergilər',
            'financing' => 'Təsisçiyə ödənişlər (bank)',
            'capex' => 'Əsas vəsait alışı',
            'refund' => 'Müştəriyə geri ödəniş',
            'fx' => 'Valyuta əməliyyatları',
            default => 'Təsnif olunmamış ödənişlər',
        };
    }

    /**
     * Xərc qrupunun cari dildə adı (qrupun özü DB-də Azərbaycanca saxlanılır).
     */
    public static function groupLabel(?string $group): string
    {
        return match ($group) {
            'Əmək haqqı' => __('Əmək haqqı'),
            'Sosial sığorta (DSMF)' => __('Sosial sığorta (DSMF)'),
            'İcbari tibbi sığorta' => __('İcbari tibbi sığorta'),
            'İcarə' => __('İcarə'),
            'Nəqliyyat və logistika' => __('Nəqliyyat və logistika'),
            'Yanacaq' => __('Yanacaq'),
            'Rabitə' => __('Rabitə'),
            'IT və proqram təminatı' => __('IT və proqram təminatı'),
            'Reklam və marketinq' => __('Reklam və marketinq'),
            'Audit, mühasibat və məsləhət' => __('Audit, mühasibat və məsləhət'),
            'Sığorta' => __('Sığorta'),
            'Ezamiyyə' => __('Ezamiyyə'),
            'Bank xidməti' => __('Bank xidməti'),
            'Maliyyə xərcləri (kurs fərqi)' => __('Maliyyə xərcləri (kurs fərqi)'),
            'Cərimə və sanksiyalar' => __('Cərimə və sanksiyalar'),
            'Ofis və təsərrüfat' => __('Ofis və təsərrüfat'),
            'Fiziki şəxslərə xidmət haqqı' => __('Fiziki şəxslərə xidmət haqqı'),
            'Sair xərclər' => __('Sair xərclər'),
            'Vergilər' => __('Vergilər'),
            'Təsisçiyə ödənişlər (bank)' => __('Təsisçiyə ödənişlər (bank)'),
            'Əsas vəsait alışı' => __('Əsas vəsait alışı'),
            'Müştəriyə geri ödəniş' => __('Müştəriyə geri ödəniş'),
            'Valyuta əməliyyatları' => __('Valyuta əməliyyatları'),
            'Təsnif olunmamış ödənişlər' => __('Təsnif olunmamış ödənişlər'),
            null, '' => '—',
            default => $group,
        };
    }

    /**
     * Qayda: [normallaşdırılmış ad üzrə regex, növ, xərc qrupu, izah, əminlik].
     *
     * @return list<array{0: string, 1: string, 2: string|null, 3: string, 4: string}>
     */
    private static function rules(): array
    {
        $low = 'low';
        $high = 'high';

        return [
            // xərc olmayanlar
            ['/ellis enterprises/', 'cogs', null, __('Valvoline idxalı (mal alışı) — maya dəyəri anbar modulunda alışlardan hesablanır'), $high],
            ['/^ygb$/', 'cogs', null, __('YGB — yük gömrük bəyannaməsi (gömrük rüsumu + idxal ƏDV-si): malın maya dəyərinə / əvəzləşdirilən vergiyə aiddir'), $low],
            ['/^mv$/', 'tax', null, __('MV — vergi ödənişi (ehtimal: mənfəət vergisi / ƏDV); əməliyyat xərci deyil'), $low],
            ['/^edv\b|\bedv depozit/', 'tax', null, __('ƏDV — əvəzləşdirilən vergi, xərc deyil'), $high],
            ['/dividend/', 'financing', null, __('Təsisçiyə dividend — xalis mənfəətin bölüşdürülməsi, xərc deyil'), $high],
            ['/^(gelir|menfeet) vergisi/', 'tax', null, __('Gəlir / mənfəət vergisi ödənişi — əməliyyat xərci deyil'), $high],
            ['/tesisci/', 'financing', null, __('Təsisçidən alınan borc (balansda 76 «təsisçi borc müqaviləsi») — gəlir deyil'), $high],
            ['/geri qaytarilib|\bqaytarma\b/', 'refund', null, __('Müştəriyə pulun qaytarılması — satışın / debitor borcun azalması, xərc deyil'), $high],
            ['/azerbaijan technologies/', 'capex', null, __('Texnika alışı ehtimalı — əsas vəsaitdirsə amortizasiya yolu ilə xərcə düşür'), $low],
            ['/yeniden qiymetlendirme/', 'fx', null, __('Valyuta qalığının yenidən qiymətləndirilməsi — pul hərəkəti deyil'), $high],
            // əməliyyat xərcləri
            ['/cerime/', 'expense', 'Cərimə və sanksiyalar', __('Cərimə (vergi məqsədləri üçün çıxılmır)'), $high],
            ['/^emek haqqi/', 'expense', 'Əmək haqqı', __('İşçilərin əmək haqqı'), $high],
            ['/^dsmf$/', 'expense', 'Sosial sığorta (DSMF)', __('Məcburi dövlət sosial sığorta haqları'), $high],
            ['/^its$/', 'expense', 'İcbari tibbi sığorta', __('İcbari tibbi sığorta haqları'), $high],
            ['/icare|team project/', 'expense', 'İcarə', __('Ofis / anbar icarəsi'), $high],
            ['/^\d{2}[a-z]{2}\d{3}\b/', 'expense', 'İcarə', __('Avtomobil (nömrə nişanı) — fiziki şəxsdən nəqliyyat vasitəsi icarəsi'), $high],
            ['/sarr logist|ramal trans|caspian logistics|ground services|logistik|orientloq/', 'expense', 'Nəqliyyat və logistika', __('Daşınma / logistika xidməti'), $high],
            ['/avtomaster|fill service|master truck|autoparts|forsage/', 'expense', 'Nəqliyyat və logistika', __('Avtomobil xidməti / təmir'), $low],
            ['/socar petroleum|azpetrol/', 'expense', 'Yanacaq', __('Yanacaq'), $high],
            ['/azercell|aztelecom|abc telecom|telecom|^rabite/', 'expense', 'Rabitə', __('Mobil / internet rabitəsi'), $high],
            ['/hosting|domein|gps solutions|ram solution|caspos|galaxy|netcon/', 'expense', 'IT və proqram təminatı', __('IT xidməti, hosting, kassa / GPS sistemi'), $low],
            ['/express print|design street|push 30|digiboom|reklam|advert|trendmedia|creaproje/', 'expense', 'Reklam və marketinq', __('Çap, dizayn, SMS / push, təlim-marketinq'), $low],
            ['/audit|financial reporting|bukhalternative|mühasib|muhasib|taxcellent/', 'expense', 'Audit, mühasibat və məsləhət', __('Audit və mühasibat xidməti'), $high],
            ['/pasha heyat|sigorta/', 'expense', 'Sığorta', __('Sığorta haqqı'), $high],
            ['/ezamiyye/', 'expense', 'Ezamiyyə', __('Ezamiyyə xərci'), $high],
            ['/bank komissiyon|komisyon|komissiya/', 'expense', 'Bank xidməti', __('Bank və valyuta köçürmə komissiyaları'), $high],
            ['/kurs ferqi/', 'expense', 'Maliyyə xərcləri (kurs fərqi)', __('Valyuta mübadiləsində reallaşmış kurs fərqi'), $high],
            ['/protecpro|kale tekstil|sirab|rif group|bi servis|alman texnikalari|rumpel|antaris|competto|masal trade|asbc|your safety|mep fire/', 'expense', 'Ofis və təsərrüfat', __('Ofis, iş forması, su, kiçik avadanlıq və xidmətlər — yoxlayın'), $low],
            ['/diger xercler|restoran/', 'expense', 'Sair xərclər', __('Nümayəndəlik və sair xərclər'), $high],
        ];
    }

    /** @var array<string, array{type: string, expense_group: string|null, note: string|null}>|null */
    private ?array $overrides = null;

    /** @var array<string, bool>|null */
    private ?array $incomeNames = null;

    /**
     * @return array{type: string, group: string|null, note: string, confidence: string, source: string}
     */
    public function classify(string $counterparty, string $section): array
    {
        $key = NameNormalizer::normalize($counterparty);
        $this->overrides ??= DB::table('flow_classifications')->get()->keyBy('normalized')
            ->map(fn ($r) => ['type' => $r->type, 'expense_group' => $r->expense_group, 'note' => $r->note])->all();
        if (isset($this->overrides[$key])) {
            $o = $this->overrides[$key];

            return ['type' => $o['type'], 'group' => $o['expense_group'], 'note' => (string) $o['note'], 'confidence' => 'user', 'source' => 'user'];
        }

        if ($section === 'other') {
            return ['type' => 'fx', 'group' => null, 'note' => __('Digər əməliyyat gəliri / xərci (pul hərəkəti deyil)'), 'confidence' => 'high', 'source' => 'rule'];
        }
        foreach (self::rules() as [$pattern, $type, $group, $note, $confidence]) {
            if (preg_match($pattern, $key)) {
                if ($section === 'income' && $type === 'expense') {
                    break;
                }

                return ['type' => $type, 'group' => $group, 'note' => $note, 'confidence' => $confidence, 'source' => 'rule'];
            }
        }

        if ($section === 'income') {
            return ['type' => 'revenue', 'group' => null, 'note' => __('Müştəridən daxilolma — satış gəliri artıq satış faylından hesablanır, P&L-ə təkrar düşmür'), 'confidence' => 'high', 'source' => 'rule'];
        }

        // həm mədaxildə (alıcı kimi), həm ödənişdə görünən şəxs → ehtimal ki, qaytarma
        $this->incomeNames ??= DB::table('bank_flow_lines')->where('section', 'income')->distinct()->pluck('normalized')->flip()->map(fn () => true)->all();
        if (isset($this->incomeNames[$key])) {
            return ['type' => 'uncertain', 'group' => null, 'note' => __('Bu şəxs alıcı kimi də ödəniş edib — geri qaytarma və ya alış ola bilər; yoxlayın'), 'confidence' => 'low', 'source' => 'rule'];
        }
        $isCompany = (bool) preg_match('/\b(mmc|asc|qsc|ooo|oao|zao|ltd|llc|bv|gmbh|group|company|service|servis|trade|firma|firmasi|market|bank|telecom|solution|center|academy|logistics)\b/', $key);
        if (! $isCompany && preg_match('/\b(oglu|qizi)\b|^[a-z]+ [a-z]+ [a-z]+$/', $key)) {
            return ['type' => 'expense', 'group' => 'Fiziki şəxslərə xidmət haqqı', 'note' => __('Fiziki şəxsə ödəniş (xidmət / iş haqqı ehtimalı) — yoxlayın'), 'confidence' => 'low', 'source' => 'rule'];
        }

        return ['type' => 'uncertain', 'group' => null, 'note' => __('Tanınmayan kontragent — növünü seçin; seçilənə qədər P&L-ə düşmür'), 'confidence' => 'low', 'source' => 'rule'];
    }

    public static function normalize(string $counterparty): string
    {
        return NameNormalizer::normalize($counterparty);
    }
}
