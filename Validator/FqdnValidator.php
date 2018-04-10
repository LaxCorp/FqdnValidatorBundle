<?php

namespace LaxCorp\FqdnValidatorBundle\Validator;

/**
 * @inheritdoc
 */
class FqdnValidator
{

    const MESSAGE_FQDN_INVALID = 'FQDN invalid';

    /**
     * @inheritdoc
     */
    public function validate($value, &$message = null)
    {

        if (!static::isValid($value)) {
            $message = $this::MESSAGE_FQDN_INVALID;

            return false;
        }

        return true;
    }

    /**
     *
     * @param string $value
     *
     * @return bool
     */
    public static function isValid(string $value)
    {
        $value = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        if (preg_match('/^\./', $value)) {
            return false;
        } elseif (preg_match('/\.$/', $value)) {
            return false;
        } elseif (strLen($value) > 255) {
            return false;
        } elseif (preg_match('/[^a-zA-Z0-9-\.]/', $value)) {
            return false;
        }

        $segments = explode(".", $value);
        if (sizeof($segments) < 2) {
            return false;
        }

        foreach ($segments as $segment) {
            if (strlen($segment) < 1) {
                return false;
            } elseif (strLen($segment) > 63) {
                return false;
            } elseif (preg_match('/^-/', $segment)) {
                return false;
            } elseif (preg_match('/-$/', $segment)) {
                return false;
            }
        }

        return true;
    }

}