<?php

declare(strict_types=1);

namespace App\Subscriber;

/**
 * Email address normalisation and the established v5 cleanup rules.
 *
 * The rules are kept exactly as in v5 (including their quirks: some fixDomain
 * rules start again from the original domain, and markers such as "@@" make
 * an address invalid on purpose). They are behaviour, not style; change them
 * only deliberately.
 */
final class EmailNormaliser
{
    public static function normalise(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function isValid(string $email): bool
    {
        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Apply the v5 cleanup rules to a candidate address (validate the result separately). */
    public static function correct(string $email): string
    {
        $email = self::normalise($email);
        $email = self::fixCommonErrors($email);
        $email = self::addressChanges($email);
        return self::normalise(self::fixUser(self::user($email)) . '@' . self::fixDomain(self::domain($email)));
    }

    /**
     * The distinct valid addresses found in free text (one per line,
     * separated by commas, …), normalised, in order of appearance.
     *
     * @return list<string>
     */
    public static function extract(string $input): array
    {
        if (!preg_match_all('/\b([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,63})\b/i', $input, $matches)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(self::normalise(...), $matches[1]), self::isValid(...))));
    }

    public static function user(string $email): string
    {
        return explode('@', self::normalise($email), 2)[0];
    }

    public static function domain(string $email): string
    {
        return explode('@', self::normalise($email), 2)[1] ?? '';
    }

    public static function mask(string $email): string
    {
        $email = self::normalise($email);
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($local === '' || $domain === '') {
            return '***';
        }
        return mb_substr($local, 0, 1) . str_repeat('*', max(3, mb_strlen($local) - 1)) . '@' . $domain;
    }

    public static function fixUser(string $user): string
    {
        // terms to invalidate
        $nemail = self::replace("/^postmaster/","@@",$user);
        $nemail = self::replace("/^abuse/","@@",$nemail);
        // $nemail = self::replace("/^accounts/","@@",$nemail);
        $nemail = self::replace("/^billing/","@@",$nemail);
        $nemail = self::replace("/^comments/","@@",$nemail);
        $nemail = self::replace("/^media/","@@",$nemail);
        $nemail = self::replace("/^example/","@@",$nemail);
        $nemail = self::replace("/^jobs/","@@",$nemail);
        // $nemail = self::replace("/^sales/","@@",$nemail);
        // $nemail = self::replace("/^support/","@@",$nemail);
        $nemail = self::replace("/^sysadmin/","@@",$nemail);
        $nemail = self::replace("/^sysadm/","@@",$nemail);
        $nemail = self::replace("/^complaints/","@@",$nemail);
        $nemail = self::replace("/^hostmaster/","@@",$nemail);
        $nemail = self::replace("/^dns-admin/","@@",$nemail);
        $nemail = self::replace("/^dns/","@@",$nemail);
        $nemail = self::replace("/^absa/","@@",$nemail);
        $nemail = self::replace("/^e-mail[-_\.]/","",$nemail);
        $nemail = self::replace("/^email-list/","@@",$nemail);
        $nemail = self::replace("/^e-mail/","",$nemail);
        $nemail = self::replace("/^email/","",$nemail);
        $nemail = self::replace("/^e-mai/","",$nemail);
        $nemail = self::replace("/^letters/","@@",$nemail);
        $nemail = self::replace("/^mail/","@@",$nemail);
        $nemail = self::replace("/^assignments/","@@",$nemail);
        $nemail = self::replace("/^pftfrh/","@@",$nemail);
        $nemail = self::replace("/^listserv/","@@",$nemail);
        $nemail = self::replace("/^listserver/","@@",$nemail);
        $nemail = self::replace("/^webmaster/","@@",$nemail);
        $nemail = self::replace("/^pop\.wanadoo\.fr/","",$nemail);

        $nemail = str_ireplace("pop.wanadoo.fr","",$nemail);

        $nemail = str_ireplace("editor","@@",$nemail);
        $nemail = str_ireplace("newsroom","@@",$nemail);
        $nemail = str_ireplace("newsletter","@@",$nemail);
        $nemail = str_ireplace("request","@@",$nemail);
        $nemail = str_ireplace("mailman","@@",$nemail);
        $nemail = str_ireplace("helpdesk","@@",$nemail);
        $nemail = str_ireplace("ozemail.com","@",$nemail);
        $nemail = str_ireplace("eastyorkrifles","@@",$nemail);
        $nemail = str_ireplace("celeste.sneed","@@",$nemail);
        $nemail = str_ireplace("celestesneed","@@",$nemail);
        $nemail = str_ireplace(".co.za","@@",$nemail);
        $nemail = str_ireplace("-removethis-","",$nemail);
        $nemail = str_ireplace("-removethis","",$nemail);
        $nemail = str_ireplace("removethis-","",$nemail);
        $nemail = str_ireplace("removethis","",$nemail);
        $nemail = str_ireplace("noreply","@",$nemail);
        $nemail = str_ireplace("no_reply","@",$nemail);
        $nemail = str_ireplace("no-reply","@",$nemail);
        $nemail = str_ireplace("no.reply","@",$nemail);
        $nemail = str_ireplace("bounce","@@",$nemail);
        $nemail = str_ireplace("disclaimer@","@@",$nemail);
        $nemail = str_ireplace("listme","@@",$nemail);
        $nemail = str_ireplace("randomhouse","@@",$nemail);
        $nemail = str_ireplace("duplicate","@@",$nemail);
        $nemail = str_ireplace("majordomo","@@",$nemail);
        $nemail = str_ireplace("hostforweb","@@",$nemail);
        $nemail = str_ireplace("google","@@",$nemail);
        $nemail = str_ireplace("hostgator","@@",$nemail);
        $nemail = str_ireplace("listmaster","@@",$nemail);
        $nemail = str_ireplace("mailer-daemon","@@",$nemail);
        $nemail = str_ireplace("samaleprostitute","@@",$nemail);
        $nemail = str_ireplace("copyright","@",$nemail);
        $nemail = str_ireplace("callcentre","@",$nemail);
        $nemail = str_ireplace("eccmngmtescalations","@@",$nemail);
        $nemail = str_ireplace("customerservice","@",$nemail);
        $nemail = str_ireplace("customer_service","@",$nemail);
        $nemail = str_ireplace("customer-service","@",$nemail);
        $nemail = str_ireplace("customer.service","@",$nemail);
        $nemail = str_ireplace("cutomer.service","@",$nemail);
        $nemail = str_ireplace("customer-support","@",$nemail);
        $nemail = str_ireplace("customer.support","@",$nemail);
        $nemail = str_ireplace("customersupport","@",$nemail);
        $nemail = str_ireplace("customer-care","@",$nemail);
        $nemail = str_ireplace("customer.care","@",$nemail);
        $nemail = str_ireplace("customercare","@",$nemail);
        $nemail = str_ireplace("customer-","@",$nemail);
        $nemail = str_ireplace("customer.","@",$nemail);
        $nemail = str_ireplace("customer","@",$nemail);
        $nemail = str_ireplace("mailabuse","@",$nemail);
        $nemail = str_ireplace("catchall","@",$nemail);
        $nemail = str_ireplace("unsubscribe","@",$nemail);
        $nemail = str_ireplace("subscribe","@",$nemail);
        $nemail = str_ireplace("nospam","@",$nemail);
        $nemail = str_ireplace(".nospam","",$nemail);
        $nemail = str_ireplace("nospam.","",$nemail);
        $nemail = str_ireplace("nospam-","",$nemail);
        $nemail = str_ireplace("nospam","",$nemail);
        $nemail = str_ireplace("spam","@",$nemail);
        $nemail = str_ireplace("submit","@@",$nemail);
        $nemail = str_ireplace("undisclosed.recipients","@@",$nemail);
        $nemail = str_ireplace("www.","",$nemail);

        return $nemail;
    }

    public static function fixDomain(string $domain): string
    {
        // fix co.za misspellings
        $nemail = self::replace("/\-co\.za/",".co.za",$domain);
        $nemail = self::replace("/\.co\.za.+$/",".co.za",$nemail);

        $nemail = self::replace("/\.c\.za$/",".co.za",$nemail);
        $nemail = self::replace("/\.co\.xa$/",".co.za",$nemail);
        $nemail = self::replace("/\.co\.z[a-z]$/",".co.za",$nemail);
        $nemail = self::replace("/\.co\.z[a-z][a-z]$/",".co.za",$nemail);
        $nemail = self::replace("/\.com\.za$/",".co.za",$domain);
        $nemail = self::replace("/\-com\.za$/",".co.za",$domain);
        $nemail = self::replace("/\.oc\.za$/",".co.za",$nemail);
        $nemail = self::replace("/\.oc\.za.+$/",".co.za",$nemail);
        $nemail = self::replace("/\.co\.az$/",".co.za",$domain);
        $nemail = self::replace("/\.xo\.za$/",".co.za",$domain);
        $nemail = self::replace("/\.net\.co\.za$/",".co.za",$nemail);
        $nemail = self::replace("/\.xco\.za$/",".co.za",$nemail);
        $nemail = self::replace("/\.c0\.za$/",".co.za",$nemail);
        $nemail = self::replace("/\.co\.z$/",".co.za",$nemail);
        $nemail = self::replace("/\.ca\.za$/",".co.za",$nemail);
        $nemail = self::replace("/\.ca\.za\.com$/",".co.za",$nemail);
        $nemail = self::replace("/\.coza$/",".co.za",$nemail);
        $nemail = self::replace("/\.coza.+$/",".co.za",$nemail);
        $nemail = self::replace("/\.co$/",".co.za",$nemail);
        $nemail = self::replace("/\.co\/za$/",".co.za",$nemail);
        $nemail = self::replace("/\.co\.co\.za$/",".co.za",$nemail);

        // fix org.za misspellings
        $nemail = self::replace("/\.org\.za.+$/",".org.za",$nemail);

        $nemail = self::replace("/\.org\.co\.za$/",".org.za",$nemail);

        // fix ac.za misspellings
        $nemail = self::replace("/\.ac\.za.+$/",".ac.za",$nemail);

        $nemail = self::replace("/\.ac\.zay$/",".ac.za",$nemail);

        // fix gov.za misspellings
        $nemail = self::replace("/\.gov\.za.+$/",".gov.za",$nemail);
        $nemail = self::replace("/sars\.cov\.za$/","sars.gov.za",$nemail);


        $nemail = self::replace("/\.gov\.co\.za$/",".gov.za",$nemail);
        $nemail = self::replace("/\.gv\.za$/",".gov.za",$nemail);
        $nemail = self::replace("/\.goz\.za$/",".gov.za",$nemail);

        // fix co.uk misspellings
        $nemail = self::replace("/\.co\.uk.+$/",".co.uk",$nemail);

        $nemail = self::replace("/\.co\.ukco\.ukz$/",".co.uk",$nemail);
        $nemail = self::replace("/\.com\.uk$/",".co.uk",$nemail);

        // fix com misspellings
        $nemail = self::replace("/\.com.+$/",".com",$nemail);
        $nemail = self::replace("/\.coom$/",".com",$nemail);
        $nemail = self::replace("/\.c[a-z]m$/",".com",$nemail);
        $nemail = self::replace("/\.co[a-z]$/",".com",$nemail);
        $nemail = self::replace("/\.can$/",".com",$nemail);
        $nemail = self::replace("/\.cm$/",".com",$nemail);
        $nemail = self::replace("/\.caom$/",".com",$nemail);
        $nemail = self::replace("/\.[a-z]om$/",".com",$nemail);
        $nemail = self::replace("/\.doc\.com$/",".com",$nemail);
        $nemail = self::replace("/\.co\.com$/",".com",$nemail);

        // fix org misspellings
        $nemail = self::replace("/\.doc\.org$/",".org",$nemail);

        // fix net misspellings
        $nemail = self::replace("/\.net.+$/",".net",$nemail);

        $nemail = self::replace("/\.ne$/",".net",$nemail);
        $nemail = self::replace("/\.n[a-z]t$/",".net",$nemail);

        // fix biz misspellings
        $nemail = self::replace("/\.biz.+$/",".biz",$nemail);

        $nemail = self::replace("/\.bjz$/",".biz",$nemail);

        // fix info misspellings
        $nemail = self::replace("/\.info.+$/",".info",$nemail);

        // fix uncommon domain misspellings
        $nemail = self::replace("/capfspan\./","capespan.",$nemail);
        $nemail = self::replace("/livf\./","live.",$nemail);
        $nemail = self::replace("/codf\./","code.",$nemail);

        // fix aol misspellings
        $nemail = self::replace("/aol\.co\.za$/","aol.com",$nemail);
        $nemail = self::replace("/aol\.uk$/","aol.com",$nemail);
        $nemail = self::replace("/aolc\.co\.za$/","aol.com",$nemail);
        $nemail = self::replace("/aol\.co\.za$/","aol.com",$nemail);
        $nemail = self::replace("/aol\.comaol\.com$/","aol.com",$nemail);

        // fix compuserv
        $nemail = self::replace("/compuserve/","",$nemail);

        // fix earthlink.net misspellings
        $nemail = self::replace("/earthlink\.com$/","earthlink.net",$nemail);
        $nemail = self::replace("/earhtlink\.net$/","earthlink.net",$nemail);

        // fix absamail misspellings
        $nemail = self::replace("/^.?absamail.+$/","absamail.co.za",$nemail);

        $nemail = self::replace("/freemail\.absa\.co\.za$/","absamail.co.za",$nemail);
        $nemail = self::replace("/freemal\.absa\.co\.za$/","absamail.co.za",$nemail);
        $nemail = self::replace("/absamial\.co\.za$/","absamail.co.za",$nemail);
        $nemail = self::replace("/absameil\.co\.za$/","absamail.co.za",$nemail);
        $nemail = self::replace("/freemail\.co\.za$/","absamail.co.za",$nemail);
        $nemail = self::replace("/free-mail\.co\.za$/","absamail.co.za",$nemail);

        $nemail = str_ireplace("freemail.abasa.co.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("fre.abasa.co.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("freemail.bsa.co.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("fre.bsa.co.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("absa.freemail.co.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("absa.fre.co.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("absafreemail.co.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("absafre.co.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("abfreemail.absa.co.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("abfre.absa.co.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("freemail.absa.org.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("fre.absa.org.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("1freemail.absa.co.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("1fre.absa.co.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("abasamail.co.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("frggmail.absa.co.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("acsamail.co.za","absamail.co.za",$nemail);
        $nemail = str_ireplace("acsamail.com","absamail.co.za",$nemail);

        // fix hotmail misspellings
        $nemail = self::replace("/^.?hotmail.+$/","hotmail.com",$nemail);

        $nemail = self::replace("/^otmail\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^htmail\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hoymail\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hotail\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hotamil\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^homail\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hormail\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hotmai\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hotmia\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hotnail\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hotmil\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hotamail\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hotmial\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^gotmail\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hitmail\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hptmail\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hotmal\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hotmsil\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hotmeil\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^fotmail\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^hoptmail\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^holtmail\.com/","hotmail.com",$nemail);
        $nemail = self::replace("/^notmail\.com/","hotmail.com",$nemail);

        // fix webmail misspellings
        $nemail = self::replace("/^.?webmail.+$/","webmail.co.za",$nemail);

        $nemail = str_ireplace("webmail.com","webmail.co.za",$nemail);
        $nemail = str_ireplace("webail.co.za","webmail.co.za",$nemail);
        $nemail = str_ireplace("wembail.co.za","webmail.co.za",$nemail);
        $nemail = str_ireplace("webmai.co.za","webmail.co.za",$nemail);
        $nemail = str_ireplace("webamil.co.za","webmail.co.za",$nemail);
        $nemail = str_ireplace("webmal.co.za","webmail.co.za",$nemail);
        $nemail = str_ireplace("wabmail.com","webmail.co.za",$nemail);
        $nemail = str_ireplace("wemail.co.za","webmail.co.za",$nemail);
        $nemail = str_ireplace("wcbmail.co.za","webmail.co.za",$nemail);

        // fix postnet.co.za misspellings
        $nemail = str_ireplace("posnet.co.za","postnet.co.za",$nemail);

        // fix newhorizons.co.za misspellings
        $nemail = str_ireplace("newhorizaons.co.za","newhorizons.co.za",$nemail);

        // fix telkomsa.net misspellings
        $nemail = self::replace("/telkom\.s\.a\./","telkomsa.",$nemail);
        $nemail = self::replace("/telkom\.sa\./","telkomsa.",$nemail);
        $nemail = self::replace("/teljomsa\./","telkomsa.",$nemail);

        $nemail = self::replace("/^.?telkomsa.+$/","telkomsa.net",$nemail);

        $nemail = str_ireplace("telkon.net","telkomsa.net",$nemail);
        $nemail = str_ireplace("tepkomsa.net","telkomsa.net",$nemail);
        $nemail = str_ireplace("telkomnet.com","telkomsa.net",$nemail);
        $nemail = str_ireplace("telomsa.net","telkomsa.net",$nemail);

        // fix mweb misspellings
        $nemail = self::replace("/^.?mweb.+$/","mweb.co.za",$nemail);

        $nemail = str_ireplace("m.web.co.za","mweb.co.za",$nemail);
        $nemail = str_ireplace("m-web.co.za","mweb.co.za",$nemail);
        $nemail = str_ireplace("mwed.co.za","mweb.co.za",$nemail);
        $nemail = str_ireplace("1mweb.co.za","mweb.co.za",$nemail);

        // fix standardbank.co.za misspellings
        $nemail = str_ireplace("standardbank.com.net","standardbank.co.za",$nemail);
        $nemail = str_ireplace("standardcank.co.za","standardbank.co.za",$nemail);

        // fix gmail misspellings
        $nemail = self::replace("/^gmai\./","gmail",$nemail);
        $nemail = self::replace("/^gmial\./","gmail.",$nemail);
        $nemail = self::replace("/^gmaik\./","gmail.",$nemail);
        $nemail = self::replace("/^gmaial\./","gmail.",$nemail);

        $nemail = self::replace("/^.?gmail.+$/","gmail.com",$nemail);

        // fix intekom.co.za misspellings
        $nemail = self::replace("/^.?intekom.+$/","intekom.co.za",$nemail);
        $nemail = str_ireplace("intelkom.co.za","intekom.co.za",$nemail);

        // fix netactive.co.za misspellings
        $nemail = self::replace("/^.?netactive.+$/","netactive.co.za",$nemail);

        // fix yahoo misspellings
        $nemail = str_ireplace("yaqhoo","yahoo",$nemail);
        $nemail = str_ireplace("yahoio.com","yahoo.com",$nemail);

        $nemail = self::replace("/yahoo\.co\.za$/","yahoo.com",$nemail);

        $nemail = self::replace("/^.?yahoo/","yahoo",$nemail);
        $nemail = self::replace("/^.?yahooco\..+$/","yahoo.co.uk",$nemail);

        // fix ananzi.co.za misspellings
        $nemail = str_ireplace("ananzi.com","ananzi.co.za",$nemail);

        // fix iafrica.com misspellings
        $nemail = str_ireplace("iafria.com","iafrica.com",$nemail);
        $nemail = str_ireplace("aifrica.com","iafrica.com",$nemail);
        $nemail = str_ireplace("ifrica.ca.com","iafrica.com",$nemail);
        $nemail = str_ireplace("ifric.com","iafrica.com",$nemail);
        $nemail = str_ireplace("ifrica.com","iafrica.com",$nemail);
        $nemail = str_ireplace("iafirca.com","iafrica.com",$nemail);
        $nemail = str_ireplace("iarfrica.com","iafrica.com",$nemail);
        $nemail = str_ireplace("idfrica.com","iafrica.com",$nemail);
        $nemail = str_ireplace("iafriva.co.za","iafrica.com",$nemail);
        $nemail = str_ireplace("iafrica.co.za","iafrica.com",$nemail);

        $nemail = self::replace("/^iafrica.+$/","iafrica.com",$nemail);

        // transunion
        $nemail = str_ireplace("transunionitc.co.za","transunion.co.za",$nemail);

        // fix rocketmail misspellings
        $nemail = self::replace("/rockftmail/","rocketmail",$nemail);

        // fix worldonline.co.za misspellings
        $nemail = self::replace("/^.?worldonline.+$/","worldonline.co.za",$nemail);

        $nemail = str_ireplace("worlonline.co.za","worldonline.co.za",$nemail);
        $nemail = str_ireplace("worlconlinc.co.za","worldonline.co.za",$nemail);

        // fix new.co.za misspellings
        $nemail = str_ireplace("mbury.new.co.za","new.co.za",$nemail);

        // fix clicks.co.za misspellings
        $nemail = str_ireplace("clics.co.za","clicks.co.za",$nemail);

        // fix deloitte.co.za misspellings
        $nemail = str_ireplace("dfloittf.co.za","deloitte.co.za",$nemail);

        // fix neotel.co.za misspellings
        $nemail = str_ireplace("onetel.com","neotel.co.za",$nemail);

        // fix mighty.co.za misspellings
        $nemail = str_ireplace("mjghty.co.za","mighty.co.za",$nemail);

        // fix polka.co.za misspellings
        $nemail = self::replace("/^.?polka.+$/","intekom.co.za",$nemail);

        // fix nashuamobile.com misspellings
        $nemail = str_ireplace("nasuamobile.com","nashuamobile.com",$nemail);

        $nemail = str_ireplace("netconnfct.com","netconnect.com",$nemail);
        $nemail = str_ireplace("xsinct.co.za","xsinet.co.za",$nemail);

        // terms to invalidate
        $nemail = self::replace("/^lists\./","@@",$nemail);
        $nemail = self::replace("/^list\./","@@",$nemail);
        $nemail = self::replace("/^listserv\./","@@",$nemail);
        $nemail = self::replace("/^listserver\./","@@",$nemail);

        // domain name changes
        $nemail = str_ireplace("kkdisplay.co.za","storequip.co.za",$nemail);
        $nemail = str_ireplace("mpsa.co.za","mpact.co.za",$nemail);
        $nemail = str_ireplace("versapak.co.za","mpact.co.za",$nemail);
        $nemail = str_ireplace("lionpackaging.co.za","mpact.co.za",$nemail);
        $nemail = str_ireplace("sacks-online.com","sacks.za.net",$nemail);
        $nemail = str_ireplace("corobrick.co.za","corobrik.co.za",$nemail);
        $nemail = str_ireplace("nopsa.co.za","shop-sa.co.za",$nemail);
        $nemail = str_ireplace("iledi.co.za","kpec.co.za",$nemail);

        // domain spaces to invalidate
        $nemail = self::replace("/\.ac\.uk$/","@@",$nemail);
        $nemail = self::replace("/\.ac\.za$/","@@",$nemail);
        $nemail = self::replace("/\.edu$/","@@",$nemail);
        $nemail = self::replace("/\.edu\.za$/","@@",$nemail);
        $nemail = self::replace("/\.mil$/","@@",$nemail);
        $nemail = self::replace("/\.gov$/","@@",$nemail);
        $nemail = self::replace("/\.gov\.za$/","@@",$nemail);
        $nemail = self::replace("/\.gov\.uk$/","@@",$nemail);
        $nemail = self::replace("/\.gov\.sg$/","@@",$nemail);
        $nemail = self::replace("/\.gnu\.org$/","@@",$nemail);
        $nemail = self::replace("/\.org$/","@@",$nemail);
        $nemail = self::replace("/\.rr\.com$/","@@",$nemail);
        $nemail = self::replace("/\.qld$/","@@",$nemail);
        $nemail = self::replace("/\.nct$/","@@",$nemail);
        $nemail = self::replace("/\.brandt$/","@@",$nemail);
        $nemail = self::replace("/\.horn$/","@@",$nemail);
        $nemail = self::replace("/\.orh$/","@@",$nemail);
        $nemail = self::replace("/\.int$/","@@",$nemail);
        $nemail = self::replace("/\.hov$/","@@",$nemail);

        // country codes to invalidate
        $nemail = self::replace("/\.ac$/","@@",$nemail);
        $nemail = self::replace("/\.ae$/","@@",$nemail);
        $nemail = self::replace("/\.ar$/","@@",$nemail);
        $nemail = self::replace("/\.at$/","@@",$nemail);
        $nemail = self::replace("/\.au$/","@@",$nemail);
        $nemail = self::replace("/\.be$/","@@",$nemail);
        $nemail = self::replace("/\.bf$/","@@",$nemail);
        $nemail = self::replace("/\.bj$/","@@",$nemail);
        $nemail = self::replace("/\.bo$/","@@",$nemail);
        $nemail = self::replace("/\.br$/","@@",$nemail);
        $nemail = self::replace("/\.bt$/","@@",$nemail);
        $nemail = self::replace("/\.bw$/","@@",$nemail);
        $nemail = self::replace("/\.ca$/","@@",$nemail);
        $nemail = self::replace("/\.cc$/","@@",$nemail);
        $nemail = self::replace("/\.ch$/","@@",$nemail);
        $nemail = self::replace("/\.cl$/","@@",$nemail);
        $nemail = self::replace("/\.cm$/","@@",$nemail);
        $nemail = self::replace("/\.cn$/","@@",$nemail);
        $nemail = self::replace("/\.cu$/","@@",$nemail);
        $nemail = self::replace("/\.cy$/","@@",$nemail);
        $nemail = self::replace("/\.cz$/","@@",$nemail);
        $nemail = self::replace("/\.de$/","@@",$nemail);
        $nemail = self::replace("/\.dk$/","@@",$nemail);
        $nemail = self::replace("/\.do$/","@@",$nemail);
        $nemail = self::replace("/\.ec$/","@@",$nemail);
        $nemail = self::replace("/\.ed$/","@@",$nemail);
        $nemail = self::replace("/\.ee$/","@@",$nemail);
        $nemail = self::replace("/\.eg$/","@@",$nemail);
        $nemail = self::replace("/\.er$/","@@",$nemail);
        $nemail = self::replace("/\.es$/","@@",$nemail);
        $nemail = self::replace("/\.eu$/","@@",$nemail);
        $nemail = self::replace("/\.fi$/","@@",$nemail);
        $nemail = self::replace("/\.fj$/","@@",$nemail);
        $nemail = self::replace("/\.fk$/","@@",$nemail);
        $nemail = self::replace("/\.fr$/","@@",$nemail);
        $nemail = self::replace("/\.gh$/","@@",$nemail);
        $nemail = self::replace("/\.gr$/","@@",$nemail);
        $nemail = self::replace("/\.gt$/","@@",$nemail);
        $nemail = self::replace("/\.hk$/","@@",$nemail);
        $nemail = self::replace("/\.hr$/","@@",$nemail);
        $nemail = self::replace("/\.hu$/","@@",$nemail);
        $nemail = self::replace("/\.id$/","@@",$nemail);
        $nemail = self::replace("/\.ie$/","@@",$nemail);
        $nemail = self::replace("/\.il$/","@@",$nemail);
        $nemail = self::replace("/\.in$/","@@",$nemail);
        $nemail = self::replace("/\.ir$/","@@",$nemail);
        $nemail = self::replace("/\.is$/","@@",$nemail);
        $nemail = self::replace("/\.it$/","@@",$nemail);
        $nemail = self::replace("/\.je$/","@@",$nemail);
        $nemail = self::replace("/\.jo$/","@@",$nemail);
        $nemail = self::replace("/\.jp$/","@@",$nemail);
        $nemail = self::replace("/\.ke$/","@@",$nemail);
        $nemail = self::replace("/\.kr$/","@@",$nemail);
        $nemail = self::replace("/\.kw$/","@@",$nemail);
        $nemail = self::replace("/\.lb$/","@@",$nemail);
        $nemail = self::replace("/\.lc$/","@@",$nemail);
        $nemail = self::replace("/\.lk$/","@@",$nemail);
        $nemail = self::replace("/\.ls$/","@@",$nemail);
        $nemail = self::replace("/\.lu$/","@@",$nemail);
        $nemail = self::replace("/\.lv$/","@@",$nemail);
        $nemail = self::replace("/\.ma$/","@@",$nemail);
        $nemail = self::replace("/\.mk$/","@@",$nemail);
        $nemail = self::replace("/\.ml$/","@@",$nemail);
        $nemail = self::replace("/\.mm$/","@@",$nemail);
        $nemail = self::replace("/\.mt$/","@@",$nemail);
        $nemail = self::replace("/\.mu$/","@@",$nemail);
        $nemail = self::replace("/\.mx$/","@@",$nemail);
        $nemail = self::replace("/\.my$/","@@",$nemail);
        $nemail = self::replace("/\.mz$/","@@",$nemail);
        $nemail = self::replace("/\.na$/","@@",$nemail);
        $nemail = self::replace("/\.ng$/","@@",$nemail);
        $nemail = self::replace("/\.nl$/","@@",$nemail);
        $nemail = self::replace("/\.no$/","@@",$nemail);
        $nemail = self::replace("/\.np$/","@@",$nemail);
        $nemail = self::replace("/\.nu$/","@@",$nemail);
        $nemail = self::replace("/\.nz$/","@@",$nemail);
        $nemail = self::replace("/\.om$/","@@",$nemail);
        $nemail = self::replace("/\.pe$/","@@",$nemail);
        $nemail = self::replace("/\.pg$/","@@",$nemail);
        $nemail = self::replace("/\.ph$/","@@",$nemail);
        $nemail = self::replace("/\.pk$/","@@",$nemail);
        $nemail = self::replace("/\.pl$/","@@",$nemail);
        $nemail = self::replace("/\.pt$/","@@",$nemail);
        $nemail = self::replace("/\.py$/","@@",$nemail);
        $nemail = self::replace("/\.qa$/","@@",$nemail);
        $nemail = self::replace("/\.ro$/","@@",$nemail);
        $nemail = self::replace("/\.ru$/","@@",$nemail);
        $nemail = self::replace("/\.rw$/","@@",$nemail);
        $nemail = self::replace("/\.sa$/","@@",$nemail);
        $nemail = self::replace("/\.se$/","@@",$nemail);
        $nemail = self::replace("/\.sg$/","@@",$nemail);
        $nemail = self::replace("/\.si$/","@@",$nemail);
        $nemail = self::replace("/\.sk$/","@@",$nemail);
        $nemail = self::replace("/\.sy$/","@@",$nemail);
        $nemail = self::replace("/\.sz$/","@@",$nemail);
        $nemail = self::replace("/\.th$/","@@",$nemail);
        $nemail = self::replace("/\.tn$/","@@",$nemail);
        $nemail = self::replace("/\.tr$/","@@",$nemail);
        $nemail = self::replace("/\.tv$/","@@",$nemail);
        $nemail = self::replace("/\.tz$/","@@",$nemail);
        $nemail = self::replace("/\.tw$/","@@",$nemail);
        $nemail = self::replace("/\.ua$/","@@",$nemail);
        $nemail = self::replace("/\.ug$/","@@",$nemail);
        $nemail = self::replace("/\.us$/","@@",$nemail);
        $nemail = self::replace("/\.uy$/","@@",$nemail);
        $nemail = self::replace("/\.uz$/","@@",$nemail);
        $nemail = self::replace("/\.vn$/","@@",$nemail);
        $nemail = self::replace("/\.ye$/","@@",$nemail);
        $nemail = self::replace("/\.yu$/","@@",$nemail);
        $nemail = self::replace("/\.zm$/","@@",$nemail);
        $nemail = self::replace("/\.zw$/","@@",$nemail);

        return $nemail;
    }

    public static function fixCommonErrors(string $email): string
    {
        // fix common errors
        $nemail = self::replace("/^-{1,10}/","",$email);
        $nemail = self::replace("/^_{1,10}/","",$nemail);
        $nemail = self::replace("/-{2,10}/","-",$nemail);
        $nemail = self::replace("/\.{2,10}/",".",$nemail);
        // $nemail = self::replace("/^20/","",$nemail);
        // $nemail = self::replace("/%20/","",$nemail);
        // $nemail = self::replace("/^3[a-d]/","",$nemail);
        $nemail = self::replace("/^mailto\./","",$nemail);
        $nemail = self::replace("/^mailto/","",$nemail);
        $nemail = self::replace("/^smtp/","",$nemail);
        $nemail = self::replace("/^address/","",$nemail);
        $nemail = self::replace("/^addr/","",$nemail);
        // $nemail = self::replace("/^[0-9]/","@@",$nemail);
        $nemail = self::replace("/\.-|-\./",".",$nemail);
        $nemail = self::replace("/\.@|@\./","@",$nemail);
        $nemail = self::replace("/-@|@-/","@",$nemail);
        $nemail = self::replace("/_@|@_/","@",$nemail);

        return $nemail;
    }

    public static function addressChanges(string $email): string
    {
        // address changes
        $nemail = str_ireplace("thecotmpanyofwinepeople","thecompanyofwinepeople",$email);
        $nemail = str_ireplace("busybean@themugg.com","busybean@muggandbean.co.za",$nemail);
        $nemail = str_ireplace("dawie@inetcom.co.za","dawiec@telkomsa.net",$nemail);
        $nemail = str_ireplace("candice@gavinmostert.co.za","admin@gavinmostert.co.za",$nemail);
        $nemail = str_ireplace("charlene@eagleteam.co.za","info@eagleteam.co.za",$nemail);
        $nemail = str_ireplace("chris@thembalitsha.org.za","grant@thembalitsha.org.za",$nemail);
        $nemail = str_ireplace("darkwing@tiscali.co.za","trevorwbp@hotmail.com",$nemail);
        $nemail = str_ireplace("cate@moneytalk.co.za","cate.hannocks@consolidatedec.co.za",$nemail);
        $nemail = str_ireplace("david@moneytalk.co.za","david.szuhanyi@consolidatedec.co.za",$nemail);
        $nemail = str_ireplace("andrew@southernkitchens.co.za","wayne@homeconcept.co.za",$nemail);
        $nemail = str_ireplace("kscp@vaal.net","capot@claydisposal.com",$nemail);
        $nemail = str_ireplace("mfest@metmissions.org.za","info@mfestpretoria.org",$nemail);
        $nemail = str_ireplace("mkahn@tppsa.co.za","janieb@tppsa.co.za",$nemail);
        $nemail = str_ireplace("mike@khfreightgroup.com","clint.hendrickse@khfreightgroup.com",$nemail);
        $nemail = str_ireplace("mark@sstream.co.za","markgelman.ct@gmail.com",$nemail);
        $nemail = str_ireplace("martin@principia.za.net","martin@m2skills.co.za",$nemail);
        $nemail = str_ireplace("rasheed@afripile.co.za","afripilerasheed@gmail.com",$nemail);
        $nemail = str_ireplace("info@lifenergy.co.za","sabinethomas@tiscali.co.za",$nemail);
        $nemail = str_ireplace("jacocoetzee@wesconstruction.co.za","info@wesconstruction.co.za",$nemail);
        $nemail = str_ireplace("stiaandreyer@boshoffvisser.co.za","stiaan@bvfd.co.za",$nemail);
        $nemail = str_ireplace("info@chengineering.co.za","brandt@philor.co.za",$nemail);
        $nemail = str_ireplace("warren@jumbozw.com","warrenzw@gmail.com",$nemail);
        $nemail = str_ireplace("arethavdmerwe@potential-unlimited.co.za","aretha@potential-unlimited.co.za",$nemail);
        $nemail = str_ireplace("vorster@calicom.co.za","vorster@bekkergauche.co.za",$nemail);
        $nemail = str_ireplace("alon@iwi.co.za","alon@togsa.co.za",$nemail);
        $nemail = str_ireplace("cobusvv@freys.co.za","gails@freys.co.za",$nemail);
        $nemail = str_ireplace("jeremy@thebutchery.co.za","jeremy@clubweb.co.za",$nemail);
        $nemail = str_ireplace("ursula.scott@ceu.co.za","ursulas@mirabilisafrica.com",$nemail);
        $nemail = str_ireplace("janine@iwi.co.za","janine@togsa.co.za",$nemail);
        $nemail = str_ireplace("info@workshop.co.za","annecswart@gmail.com",$nemail);
        $nemail = str_ireplace("anne@ysa-lapin.com","anne@ysa.co.za",$nemail);
        $nemail = str_ireplace("jr@debt-therapy.net","jorgen@rosvall.co.za",$nemail);
        $nemail = str_ireplace("mdutoit@dectrust.co.za","ceo@dectrust.co.za",$nemail);
        $nemail = str_ireplace("ipcplumbing@my.co.za","office@ipcplumbing.co.za",$nemail);
        $nemail = str_ireplace("capenat@new.co.za","info@rooibostea.co.za",$nemail);
        $nemail = str_ireplace("petros@leighgroup.co.za","leigh@leighgroup.co.za",$nemail);
        $nemail = str_ireplace("tmotsoane@wmsgaming.co.za","potto@wmsgaming.co.za",$nemail);
        $nemail = str_ireplace("loukie7@gmail.com","loudine@puredelight.co.za",$nemail);
        $nemail = str_ireplace("mike@kiabrokers.co.za","michael.olivier@telkomsa.net",$nemail);
        $nemail = str_ireplace("piemichelle@piemanspantry.co.za","michelles@foodcorp.co.za",$nemail);
        $nemail = str_ireplace("michelle@nisc.co.za","michelle@itbsoftware.co.za",$nemail);
        $nemail = str_ireplace("maor@balkanology.co.za","maor@thebeanstalk.co.za",$nemail);
        $nemail = str_ireplace("moutonwj@netline.co.za","admin@drmouton.co.za",$nemail);
        $nemail = str_ireplace("duffuel@vaal.net","enviro@claydisposal.com",$nemail);

        return $nemail;
    }

    /**
     * preg_replace() for the fixed rule patterns above: a regex error (which
     * preg_replace reports as null) is a programming error, not an address.
     */
    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? throw new \LogicException('Address rule failed: ' . $pattern);
    }
}
