<?php

declare(strict_types=1);

namespace App\Tests\Integration\CattoMail;

use App\CattoMail\UnsubscribeLinks;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\TerminableInterface;

/** The unsubscribe_url catto-mail puts into List-Unsubscribe (RFC 8058): signed, ctnlist-only, one list. */
final class OneClickUnsubscribeTest extends CattoMailTestCase
{
    public function testOneClickPostUnsubscribesFromThatListOnly(): void
    {
        $news = $this->createList('NEWS', 'News');
        $deals = $this->createList('DEALS', 'Deals');
        $id = $this->createSubscriber('ann@example.com');
        $this->setMembership($id, $news, true);
        $this->setMembership($id, $deals, true);
        $muid = $this->createMessage('Campaign', [$news]);
        $url = $this->service(UnsubscribeLinks::class)->url($this->subscriberUuid($id), 'NEWS', $muid);
        $path = (string) parse_url($url, PHP_URL_PATH);

        $page = $this->request('GET', $path);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Unsubscribe from News', (string) $page->getContent());
        self::assertFalse((bool) $this->db->fetchOne('SELECT ls_unsubscribed FROM list_subscribers WHERE ls_s_id = ? AND ls_l_id = ?', [$id, $news]), 'GET (link scanners) changes nothing');

        // What a mail client sends for List-Unsubscribe-Post: no cookie, no CSRF token.
        $done = $this->request('POST', $path, 'List-Unsubscribe=One-Click');
        self::assertSame(200, $done->getStatusCode());
        self::assertTrue((bool) $this->db->fetchOne('SELECT ls_unsubscribed FROM list_subscribers WHERE ls_s_id = ? AND ls_l_id = ?', [$id, $news]));
        self::assertFalse((bool) $this->db->fetchOne('SELECT ls_unsubscribed FROM list_subscribers WHERE ls_s_id = ? AND ls_l_id = ?', [$id, $deals]), 'other lists unchanged');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT sml_unsubscribe FROM smlog WHERE sml_muid = ?', [$muid]));
        self::assertSame([], $this->fake->requests, 'ordinary unsubscribe state stays in ctnlist');

        $again = $this->request('POST', $path, 'List-Unsubscribe=One-Click');
        self::assertSame(200, $again->getStatusCode(), 'repeating it is harmless');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT sml_unsubscribe FROM smlog WHERE sml_muid = ?', [$muid]), 'and counted once');

        self::assertSame(404, $this->request('POST', substr($path, 0, -3) . 'AAA')->getStatusCode(), 'bad signature');
        self::assertSame(404, $this->request('POST', str_replace('/NEWS/', '/DEALS/', $path))->getStatusCode(), 'the signature is per list');
        // Someone else's identity in the path with this signature unsubscribes nobody.
        $other = $this->createSubscriber('ben@example.com');
        $this->setMembership($other, $deals, true);
        self::assertSame(404, $this->request('POST', str_replace($this->subscriberUuid($id), $this->subscriberUuid($other), str_replace('/NEWS/', '/DEALS/', $path)))->getStatusCode(), 'another subscriber');
        self::assertSame(404, $this->request('POST', str_replace('/' . $muid . '/', '/' . str_repeat('b', 32) . '/', $path))->getStatusCode(), 'another message');
        self::assertFalse((bool) $this->db->fetchOne('SELECT ls_unsubscribed FROM list_subscribers WHERE ls_s_id = ? AND ls_l_id = ?', [$other, $deals]));
        self::assertSame([], $this->fake->requests, 'never a catto-mail opt-out');
    }

    private function request(string $method, string $path, string $body = ''): Response
    {
        $request = Request::create($path, $method, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body);
        $kernel = self::$kernel ?? throw new \LogicException('No kernel.');
        $response = $kernel->handle($request);
        if ($kernel instanceof TerminableInterface) {
            $kernel->terminate($request, $response);
        }
        return $response;
    }
}
