<?php
/**
 * Simple TOTP Helper Class
 * Implements RFC 6238 Time-Based One-Time Password Algorithm
 */
class TOTP {
    
    /**
     * Generate a new random Base32 secret
     * @param int $length Length of the secret (default 16 chars = 80 bits)
     * @return string Base32 secret
     */
    public static function createSecret($length = 16) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Calculate the code for a given secret and time slice
     * @param string $secret Base32 secret
     * @param int|null $timeSlice Time slice (timestamp / 30)
     * @return string 6-digit code
     */
    public static function getCode($secret, $timeSlice = null) {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }

        $secretKey = self::base32Decode($secret);
        
        // Pack time into binary string (8 bytes, big-endian)
        $time = chr(0) . chr(0) . chr(0) . chr(0) . pack('N*', $timeSlice);
        
        // Hash it with HMAC-SHA1
        $hmac = hash_hmac('sha1', $time, $secretKey, true);
        
        // Use last nibble of hmac as offset
        $offset = ord(substr($hmac, -1)) & 0x0F;
        
        // Read 4 bytes from the offset
        $hashPart = substr($hmac, $offset, 4);
        
        // Unpack to integer
        $value = unpack('N', $hashPart);
        $value = $value[1];
        
        // Mask with 0x7FFFFFFF
        $value = $value & 0x7FFFFFFF;
        
        // Modulo 10^6
        $modulo = $value % 1000000;
        
        // Pad with zeros to 6 digits
        return str_pad($modulo, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a code
     * @param string $secret Base32 secret
     * @param string $code Code to verify
     * @param int $discrepancy Allowed time drift in 30-second units (default 1 = +/- 30s)
     * @return bool True if valid
     */
    public static function verifyCode($secret, $code, $discrepancy = 1) {
        $currentTimeSlice = floor(time() / 30);
        
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = self::getCode($secret, $currentTimeSlice + $i);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generate Google Authenticator QR Code URL
     * @param string $name Account name (e.g. user@example.com)
     * @param string $secret Base32 secret
     * @param string $issuer Issuer name (e.g. MyApp)
     * @return string QR Code URL
     */
    public static function getQRCodeUrl($name, $secret, $issuer = 'SF10 System') {
        $urlencoded = urlencode($name);
        $issuerEncoded = urlencode($issuer);
        return "otpauth://totp/$issuerEncoded:$urlencoded?secret=$secret&issuer=$issuerEncoded";
    }

    /**
     * Decode Base32 string
     * @param string $secret
     * @return string Binary data
     */
    private static function base32Decode($secret) {
        if (empty($secret)) return '';
        
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32charsFlipped = array_flip(str_split($base32chars));
        
        $paddingCharCount = substr_count($secret, '=');
        $allowedValues = array(6, 4, 3, 1, 0);
        if (!in_array($paddingCharCount, $allowedValues)) return false;
        
        for ($i = 0; $i < 4; $i++) {
            if ($paddingCharCount == $allowedValues[$i] &&
                substr($secret, -($allowedValues[$i])) != str_repeat('=', $allowedValues[$i])) return false;
        }
        
        $secret = str_replace('=', '', $secret);
        $secret = str_split($secret);
        $binaryString = '';
        
        foreach ($secret as $char) {
            if (!isset($base32charsFlipped[$char])) return false;
            $binaryString .= str_pad(decbin($base32charsFlipped[$char]), 5, '0', STR_PAD_LEFT);
        }
        
        $eightBits = str_split($binaryString, 8);
        $binaryResult = '';
        
        foreach ($eightBits as $z) {
            if (strlen($z) < 8) break;
            $binaryResult .= chr(bindec($z));
        }
        
        return $binaryResult;
    }
}
?>