<?php
/**
 * Utilidades de Seguridad y Cifrado Reversible
 * Implementa cifrado AES-256-CBC con vector de inicialización (IV) dinámico
 */

class Security {
    // Clave de cifrado simétrico de 256 bits (Hexadecimal de 64 caracteres)
    // ADVERTENCIA: En un entorno de producción real, esta clave debe almacenarse en variables de entorno o fuera del directorio público.
    private static $encryption_key = 'b23a9d9431ec5e963bc1843ad8ea607f2df47bfd62054c30c8da26e2e5e1a3d9';

    /**
     * Cifra una cadena de texto de forma segura usando AES-256-CBC
     * 
     * @param string $data El texto plano a cifrar (ej. contraseña)
     * @return string La cadena cifrada en formato Base64 lista para guardarse en la DB
     */
    public static function encrypt($data) {
        if ($data === null || $data === '') {
            return '';
        }

        $method = 'aes-256-cbc';
        $key = hex2bin(self::$encryption_key);
        
        // Determinar longitud del IV necesario y generar uno aleatorio
        $iv_length = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($iv_length);
        
        // Cifrar los datos
        $encrypted = openssl_encrypt($data, $method, $key, OPENSSL_RAW_DATA, $iv);
        
        // Concatenar el IV (binario) al inicio del texto cifrado y codificar todo en Base64
        // Esto permite guardar el IV junto con los datos sin tener una columna separada.
        return base64_encode($iv . $encrypted);
    }

    /**
     * Descifra una cadena codificada en Base64 que contiene los datos cifrados y el IV
     * 
     * @param string $encryptedData El bloque Base64 recuperado de la DB
     * @return string El texto descifrado en texto plano
     */
    public static function decrypt($encryptedData) {
        if (empty($encryptedData)) {
            return '';
        }

        $method = 'aes-256-cbc';
        $key = hex2bin(self::$encryption_key);
        
        // Decodificar Base64
        $raw_data = base64_decode($encryptedData);
        
        $iv_length = openssl_cipher_iv_length($method);
        
        // Validar tamaño mínimo de los datos decodificados (deben ser mayores al IV)
        if (strlen($raw_data) <= $iv_length) {
            return '';
        }
        
        // Extraer el IV del inicio
        $iv = substr($raw_data, 0, $iv_length);
        
        // Extraer el texto cifrado
        $encrypted = substr($raw_data, $iv_length);
        
        // Descifrar
        $decrypted = openssl_decrypt($encrypted, $method, $key, OPENSSL_RAW_DATA, $iv);
        
        return $decrypted !== false ? $decrypted : '';
    }
}
