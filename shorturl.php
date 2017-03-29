<?php

/**
 * rbib/util/string/encode/Bijection.php
 * $Date:: #$
 * @version $Rev$
 * @package 
 */

// namespace rbib\util\string\encode;

// class Bijection
class shorturl
{

    /**
     * Char Set for short URL
     */
    const BIJ_SET = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Checker for short URL
     * 
     * @var array
     */
    private $mAlphabetChecker = array(
        50,
        51,
    );

    /**
     * The secret string 
     * 
     * @var string
     */
    private $mAlphabet = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Create random string
     * 
     * @param int $loop
     * @return string
     */
    public function cryptAlphabet($loop = 60)
    {
        $mAlphabet = self::BIJ_SET;
        for ($i = 0; $i < $loop; $i++) {
            str_shuffle($mAlphabet);
        }
        return $mAlphabet;
    }

    /**
     * Return an encoded URI
     * 
     * @param int|string $id id (only positive integer accepted) to be encoded
     * @param bool $checkDigit checkDigit (true) adds redundancy check digit or not
     * @return string|false
     */
    public function encode($id, $checkDigit = true)
    {
        if (!is_bool($checkDigit) || !is_int($id) && !is_string($id)) {
            return false;
        }

        if (!$id || ((int) $id) != $id || $id < 0) {
            return false;
        } else {
            $id = (int) $id;
        }


        list($mAlphabetArr, $mBase) = $this->getSecret($this->mAlphabet);
        if ($checkDigit) {
            $checker = $this->getCheckDigit($id, $mAlphabetArr);
        } else {
            $checker = '';
        }
        $shortURI = '';
        while ($id > 0) {
            $shortURI = $mAlphabetArr[$id % $mBase] . $shortURI;
            $id = (int) ($id / $mBase);
        }

        return $checker . $shortURI;
    }

    /**
     * Return id from an encoded URI
     * 
     * @param string $shortURI encoded URI
     * @param bool $checkDigit true detects redundancy check digit or not
     * @return int|false
     */
    public function decode($shortURI = '', $checkDigit = true)
    {
        if (!is_string($shortURI) || !is_bool($checkDigit)) {
            return false;
        }

        $checkerLen = count($this->mAlphabetChecker);
        if ($checkDigit && strlen($shortURI) < $checkerLen + 1) {
            return false;
        }

        $id = 0;
        list($mAlphabetArr, $mBase) = $this->getSecret($this->mAlphabet);

        $encodedStr = $checkDigit ? substr($shortURI, $checkerLen) : $shortURI;
        foreach (str_split($encodedStr) as $value) {
            $id = $id * $mBase + array_search($value, $mAlphabetArr);
        }

        if ($id < 0) {
            return false;
        }

        if (!$checkDigit) {
            return $id;
        } else {
            $checker = $this->getCheckDigit($id, $mAlphabetArr);
        }

        if ($checker === null || $checker !== substr($shortURI, 0, $checkerLen)) {
            return false;
        }

        return $id;
    }

    /**
     * 
     * @param string $string
     * @return array
     */
    protected function getSecret($string)
    {
        return array(str_split($string), strlen($string));
    }

    /**
     * 
     * @param int $id
     * @param array $mAlphabetArr
     * @return string|null
     */
    protected function getCheckDigit($id, array $mAlphabetArr)
    {
        if (!is_int($id)) {
            return null;
        }

        $checkDigit = '';
        foreach ($this->mAlphabetChecker as $value) {
            $index = $id % $value;
            if (isset($mAlphabetArr[$index])) {
                $checkDigit = $checkDigit . $mAlphabetArr[$index];
            } else {
                return null;
            }
        }
        return $checkDigit;
    }
}
