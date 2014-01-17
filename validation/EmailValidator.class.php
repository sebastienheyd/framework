<?php
/**
 * @package framework.validation
 */
class validation_EmailValidator extends validation_ValidatorImpl implements validation_Validator
{
	/**
	 * FIX #46269 - source http://atranchant.developpez.com/code/validation/
	 */
	const EMAIL_REGEXP = "/^(?!(?:(?:\\x22?\\x5C[\\x00-\\x7E]\\x22?)|(?:\\x22?[^\\x5C\\x22]\\x22?)){255,})(?!(?:(?:\\x22?\\x5C[\\x00-\\x7E]\\x22?)|(?:\\x22?[^\\x5C\\x22]\\x22?)){65,}@)(?:(?:[\\x21\\x23-\\x27\\x2A\\x2B\\x2D\\x2F-\\x39\\x3D\\x3F\\x5E-\\x7E]+)|(?:\\x22(?:[\\x01-\\x08\\x0B\\x0C\\x0E-\\x1F\\x21\\x23-\\x5B\\x5D-\\x7F]|(?:\\x5C[\\x00-\\x7F]))*\\x22))(?:\\.(?:(?:[\\x21\\x23-\\x27\\x2A\\x2B\\x2D\\x2F-\\x39\\x3D\\x3F\\x5E-\\x7E]+)|(?:\\x22(?:[\\x01-\\x08\\x0B\\x0C\\x0E-\\x1F\\x21\\x23-\\x5B\\x5D-\\x7F]|(?:\\x5C[\\x00-\\x7F]))*\\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-+[a-z0-9]+)*\\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-+[a-z0-9]+)*)|(?:\\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\\]))$/iD";
	

	public function __construct()
	{
		$this->setParameter(true);
	}


	/**
	 * Validate $data and append error message in $errors.
	 *
	 * @param validation_Property $Field
	 * @param validation_Errors $errors
	 *
	 * @return void
	 */
	protected function doValidate(validation_Property $field, validation_Errors $errors)
	{
		if ($this->getParameter() == true)
		{
			$value = $field->getValue();
			if ($value !== null && $value !== '' && !self::isEmail($value))
			{
				$this->reject($field->getName(), $errors);
			}
		}
	}

	/**
	 * @param string $email
	 * @return boolean
	 */
	public static function isEmail($email)
	{
		if (!is_string($email) || $email === '' || strlen($email) > 255)
		{
			return false;
		}
		elseif (self::useBuiltinValidator())
		{	
			return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
		}
		return preg_match(self::EMAIL_REGEXP, $email);
	}

	/**
	 * Sets the value of the unique validator's parameter.
	 *
	 * @param mixed $value
	 */
	public function setParameter($value)
	{
		parent::setParameter(validation_BooleanValueParser::getValue($value));
	}
	
	/**
	 * @var boolean
	 */
	protected static $enableBuiltinValidator = null;
	
	/**
	 * Default behavior is to use builtin validator if 'filter_var' is available
	 * As long as this is not disabled by DISABLE_PHP_BUILTIN_MAIL_VALIDATOR
	 * @return boolean
	 */
	protected static function useBuiltinValidator()
	{
		if(null === self::$enableBuiltinValidator)
		{
			self::$enableBuiltinValidator = false;
			if(function_exists('filter_var'))
			{
				self::$enableBuiltinValidator = true;
				
				if(defined('DISABLE_PHP_BUILTIN_MAIL_VALIDATOR') && DISABLE_PHP_BUILTIN_MAIL_VALIDATOR)
				{
					self::$enableBuiltinValidator = false;
				}
			}	
		}
	
		return self::$enableBuiltinValidator;
	}	
}