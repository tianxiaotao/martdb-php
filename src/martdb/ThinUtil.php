<?php
namespace martdb;

/**
 * 支持的数据类型
 *
 * @author Chunlin Jing
 */
class Types
{
  // Cannot use 'static' as constant modifier: public static const
  // PHP OOP is relatively weak
  public const BOOLEAN_FALSE = 0x00;
  public const BOOLEAN_TRUE  = 0x01;
  public const I32           = 0x20;
  public const I64           = 0x40;
  public const FLOAT         = 0x60;
  public const DOUBLE        = 0x70;
  public const STRING        = -0x80;
  public const BYTES         = -0x70;
  public const LIST          = -0x60;
  public const MAP           = -0x50;
  public const NULL          = -0x10;
}

/** Custom class/interface */
/**
 * Thin Util contains binary encoded/decoded methods
 *
 * @author Chunlin Jing
 */
class ThinUtil
{
  /**
   * Copy src array to dest array
   */
  public static function arraycopy(&$src, $srcPos, &$dest, $destPos, $length) {
    for ($i = 0; $i < $length; $i++) {
      $dest[$destPos++] = $src[$srcPos++];
    }
  }

  /**
   * Convert to signed byte
   */
  public static function toByte($value) {
    return $value > 127 ? $value - 256 : $value;
  }

  // Output unsigned byte array() string(Thrift binary type)
  public static function array2String($bytes) {
    $str = '';
    for ($i = 0, $j = count($bytes); $i < $j; $i++) {
      $b = $bytes[$i];
      $b = $b < 0 ? $b + 256 : $b;
      $str .= chr($b);
    }
    return $str;
  }

  private static $le = null;
  public static function isLittleEndian() {
    if (is_null(self::$le)) {
      self::$le = self::_isLittleEndian();
    }
    return self::$le;
  }

  private static function _isLittleEndian() {
    $t = 0x00FF;
    $p = pack('S', $t);
    return $t === current(unpack('v', $p));
  }
}

/** Custom exception */
class TIllegalArgumentException extends \Exception
{
}

class TIndexOutOfBoundsException extends \Exception
{
}

class MismatchTypeException extends \Exception
{
}

/**
 * ==================================================
 * The Class is Output stream
 * ==================================================
 */
class CodedOutputStream
{
  private $buffer = array();
  private $limit = 0;
  private $position = 0;
  private $output = array();

  public const DEFAULT_BUFFER_SIZE = 4096;

  public function __construct($bufferSize = self::DEFAULT_BUFFER_SIZE)
  {
    if ($bufferSize < 1)
    {
      $bufferSize = DEFAULT_BUFFER_SIZE;
    }
    $this->limit = $bufferSize;
  }

  /** Write a single byte. */
  public function writeRawByte($value) {
    if ($this->position == $this->limit) {
      $this->refreshBuffer();
    }
    $value &= 0xff;
    $this->buffer[$this->position++] = ($value > 127 ? $value - 256 : $value);
  }

  /** Write an array of bytes. */
  public function writeRawBytes($value, $offset = 0, $length = -1) {
    // PHP has no way to overload
    if ($length < 0) {
      $length = count($value);
    }
    if ($this->limit - $this->position >= $length) {
      ThinUtil::arraycopy($value, $offset, $this->buffer, $this->position, $length);
      $this->position += $length;
    } else {
      $bytesWritten = $this->limit - $this->position;
      if ($bytesWritten > 0) {
        ThinUtil::arraycopy($value, $offset, $this->buffer, $this->position, $bytesWritten);
        $offset += $bytesWritten;
        $length -= $bytesWritten;
        $this->position = $this->limit;
      }
      $this->refreshBuffer();
      if ($length <= $this->limit) {
        ThinUtil::arraycopy($value, $offset, $this->buffer, 0, $length);
        $this->position = $length;
      } else {
        // Write is very big.  Let's do it all at once.
        ThinUtil::arraycopy($value, $offset, $this->output, count($this->output), $length);
      }
    }
  }

  /** Write a {@code bool} field, including tag, to the stream. */
  public function writeBool($value) {
    $this->writeRawByte($value ? Types::BOOLEAN_TRUE : Types::BOOLEAN_FALSE);
  }

  /** Write a {@code i32/int} field, including tag, to the stream. */
  public function writeI32($value) {
    $isNeg = $value < 0;
    if ($isNeg) {
        $value = abs(++$value);  // Math methods is inner function
    }
    $size = $this->computeNumberSize($value);
    $this->writeRawByte(Types::I32 | ($isNeg ? 1 << 3 : 0) | ($size - 1));
    $this->putNumber($value, $size);
  }

  /** Write a {@code i64/long} field, including tag, to the stream. */
  public function writeI64($value) {
    $isNeg = ($value < 0);
    if ($isNeg) {
      if ($value == PHP_INT_MIN) {
        $value = PHP_INT_MAX;
      } else {
        $value = abs(++$value);
      }
    }
    $size = $this->computeNumberSize($value);
    $this->writeRawByte(Types::I64 | ($isNeg ? 8 : 0) | ($size - 1));
    $this->putNumber($value, $size);
  }

  /** Write a {@code float} field, including tag, to the stream. */
  public function writeFloat($value) {
    $this->writeRawByte(Types::FLOAT); // fixed32
    $this->writeRawLittleEndian32($this->floatToRawIntBits($value));
  }

  /** Write a {@code double} field, including tag, to the stream. */
  public function writeDouble($value) {
    $this->writeRawByte(Types::DOUBLE); // fixed64
    $this->writeRawLittleEndian64($this->doubleToRawIntBits($value));
  }

  /** Write a {@code string} field, including tag, to the stream. */
  public function writeString($value) {
    $length = strlen($value);
    if ($length == 0) {
      $this->writeRawByte(Types::STRING);
      return;
    }

    // must be signed char UTF-8
    $bytes = unpack("c*", $value);  // Note: PHP unpack array index start is 1 not 0
    $bytesLength = count($bytes);
    $size = $this->computeNumberSize($bytesLength);
    $this->writeRawByte(Types::STRING | $size);
    $this->putNumber($bytesLength, $size);
    $this->writeRawBytes($bytes, 1, $bytesLength);  // index start is 1
  }

  /** Write a {@code byte array} field, including tag, to the stream. */
  public function writeBytes($value, $offset = 0, $length = -1) {
    if ($length < 0) {
      $length = count($value);
    }
    if ($length == 0) {
      $this->writeRawByte(Types::BYTES);
      return;
    }
    $size = $this->computeNumberSize($length);
    $this->writeRawByte(Types::BYTES | $size);
    $this->putNumber($length, $size);
    $this->writeRawBytes($value, $offset, $length);
  }

  /** Write a {@code collection} field, including tag, to the stream. */
  public function writeCollection($value, $offset = 0, $length = -1) {
    if ($length < 0) {
      $length = $value->size();
    }
    if ($length == 0) {
      $this->writeRawByte(Types::LIST);
      return;
    }
    $size = $this->computeNumberSize($length);
    $this->writeRawByte(Types::LIST | $size);
    $this->putNumber($length, $size);
    $this->putCollection($value, $offset, $length);
  }

  /** Write a {@code map} field, including tag, to the stream. */
  public function writeMap($value) {
    // PHP map is array(key=>value, ...)
    $length = $value->size();
    if ($length == 0) {
      $this->writeRawByte(Types::MAP);
      return;
    }
    $size = $this->computeNumberSize($length);
    $this->writeRawByte(Types::MAP | $size);
    $this->putNumber($length, $size);
    $this->putMap($value);
  }

  /** Write a {@code NULL} field, including tag, to the stream. */
  public function writeNull() {
    $this->writeRawByte(Types::NULL);
  }

  /** Object != null -> toString() -> getBytes("UTF-8") */
  public function putCollection($value, $offset, $length) {
    for ($i = $offset, $j = $offset + $length; $i < $j; $i++) {
      $this->putObject($value->get($i));
    }
  }

  /** Key:Value */
  public function putMap($m) {
    $entrySet = $m->entrySet();
    foreach ($entrySet as $key => $value) {
      $this->putObject($key);
      $this->putObject($value);
    }
  }

  /**
   * PHP data type:
   *   Integer
   *   Float
   *   String
   *   Boolean
   *   Array
   *   Object
   */
  public function putObject($value) {
    if (is_string($value)) {
      $this->writeString($value);
    } else if (is_numeric($value)) { // Note: is_numeric check number and string number
      if (is_int($value)) {
        if (-2147483648 <= $value && $value <= 2147483647) {
          $this->writeI32($value);
        } else {
          $this->writeI64($value);
        }
      } else {  // is_float(): float->double
        $this->writeDouble($value);
      }
    } else if ($value instanceof TNumber) {

      switch ($value->getType()) {
        case TNumber::INT: {
          $this->writeI32($value->getNumber());
          break;
        }
        case TNumber::LONG: {
          $this->writeI64($value->getNumber());
          break;
        }
        case TNumber::FLOAT: {
          $this->writeFloat($value->getNumber());
          break;
        }
        case TNumber::DOUBLE: {
          $this->writeDouble($value->getNumber());
          break;
        }
        default: throw new TIllegalArgumentException("Parameter type must be number - ".$value);
      }

    } else if ($value instanceof TList) { // list
      $this->writeCollection($value);
    } else if ($value instanceof TMap) {  // map
      $this->writeMap($value);
    } else if (is_array($value)) { // bytes
      $this->writeBytes($value);
    } else if (is_bool($value)) { // boolean
      $this->writeBool($value);
    } else {
      if (is_null($value)) {  // null
        $this->writeNull();
      } else {
        $this->writeString(''.$value);  // __toString()
      }
    }
  }

  // this.writeRawLittleEndian32(Float.floatToRawIntBits(value));
  public function writeRawLittleEndian32($value) {
    for ($i = 0; $i < 4; $i++) {
      $this->writeRawByte($value & 0xFF);
      $value >>= 8;
    }
  }

  // float32 convert int32 by IEEE 754
  public function floatToRawIntBits($value) {
    return unpack('l*', pack('f', $value))[1];
  }

  // this.writeRawLittleEndian64(Double.doubleToRawLongBits(value));
  public function writeRawLittleEndian64($value) {
    for ($i = 0; $i < 8; $i++) {
      $this->writeRawByte($value & 0xFF);
      $value >>= 8;
    }
  }

  // float64 convert int64 by IEEE 754
  public function doubleToRawIntBits($value) {
    $a = unpack('L*', pack('d', $value));
    return ThinUtil::isLittleEndian() ? ($a[2] << 32) | $a[1] : ($a[1] << 32) | $a[2];
  }

  /**
   * little-endian.
   * PHP unsigned is not supported
   * Support PHP_INT_MAX <= value <= PHP_INT_MIN, 64bit os == java Long.MAX_VALUE/MIN_VALUE,
   * other may be loss of precision
   */
  private function putNumber($value, $size) {
    // $value >>>= 8;  // PHP is not supported >>>, >>>=
    for ($i = 0; $i < $size; $i++) {
      $this->writeRawByte($value);
      $value >>= 8;
    }
  }

  /** Compute number size. */
  private function computeNumberSize($value) {
    if ($value > 0) {
      if ($value <= 127)                return 1;
      if ($value <= 32767)              return 2;
      if ($value <= 8388607)            return 3;
      if ($value <= 2147483647)         return 4;
      if ($value <= 549755813887)       return 5;
      if ($value <= 140737488355327)    return 6;
      if ($value <= 36028797018963967)  return 7;
    } else {
      if (-128 <= $value)               return 1;
      if (-32768 <= $value)             return 2;
      if (-8388608 <= $value)           return 3;
      if (-2147483648 <= $value)        return 4;
      if (-549755813888 <= $value)      return 5;
      if (-140737488355328 <= $value)   return 6;
      if (-36028797018963968 <= $value) return 7;
    }
    return 8;
  }

  public function refreshBuffer() {
    if ($this->position == 0) {
      return;
    }
    // Since we have an output stream, this is our buffer
    // and buffer offset == 0
    ThinUtil::arraycopy($this->buffer, 0, $this->output, count($this->output), $this->position);
    $this->position = 0;
  }

  /**
   * Flushes the stream and forces any buffered bytes to be written.  This
   * does not flush the underlying OutputStream.
   */
  public function flush() {
    if (!is_null($this->output)) {
      $this->refreshBuffer();
    }
  }

  // Output unsigned byte array() string(Thrift binary type)
  public function output() {
    $this->flush();
    return ThinUtil::array2String($this->output);
  }

  public function getOutput() {
    return $this->output;
  }

}

/**
 * ==================================================
 * The Class is Input stream
 * ==================================================
 */
class CodedInputStream
{
  private $input = "";  // PHP Thrift binary type is string
  private $position = 0;
  private $limit = 0;

  public function __construct($rd) {
    if (!is_string($rd)) {
      throw new TIllegalArgumentException("Parameter must be string");
    }
    $this->input = $rd;
    $this->limit = strlen($rd);
  }

  /** Read a single byte. */
  public function readRawByte() {
    if ($this->position == $this->limit) {
      throw new TIndexOutOfBoundsException("Stream is EOF");
    }
    $value = ord(substr($this->input, $this->position++, 1));
    return $value > 127 ? $value - 256 : $value;
  }

  public function readRawBytes($size) {
    if ($size <= 0 || $this->isAtEnd()) {
      return array();
    }

    $bytes = array();
    $stream = $this->input;
    $length = min($size, $this->limit - $this->position);
    for ($i = $this->position, $j = 0; $j < $length; $i++) {
      $value = ord(substr($stream, $i, 1));
      $bytes[$j++] = $value > 127 ? $value - 256 : $value;
    }
    $this->position += $length;

    return $bytes;
  }

  /** Returns true if the stream has reached the end of the input. */
  public function isAtEnd() {
    return $this->position == $this->limit;
  }

  /** Read a {@code bool} field, including tag, to the stream. */
  public function readBool() {
    return $this->readBoolValue($this->readRawByte());
  }

  public function readBoolValue($tag) {
    switch ($tag) {
 	  case Types::BOOLEAN_TRUE: return true;
 	  case Types::BOOLEAN_FALSE: return false;
 	  default: throw new MismatchTypeException("Current byte is not Boolean");
    }
  }

  /** Read a {@code I32/int} field, including tag, to the stream. */
  public function readI32() {
    return $this->readI32Value($this->readRawByte());
  }

  public function readI32Value($tag) {
    switch ($tag & 0xf0) {
 	  case Types::I32: {
 	    $size = $this->computeNumberSize($tag);
 	    return $this->getNumber($size, (($tag & 0x08)) == 8);
 	  }
 	  default: throw new MismatchTypeException("Current byte is not I32/Int");
    }
  }

  /** Read a {@code I64/long} field, including tag, to the stream. */
  public function readI64() {
    return $this->readI64Value($this->readRawByte());
  }

  public function readI64Value($tag) {
    switch ($tag & 0xf0) {
 	  case Types::I64: {
 	    $size = $this->computeNumberSize($tag);
 	    return $this->getNumber($size, (($tag & 0x08)) == 8);
 	  }
 	  default: throw new MismatchTypeException("Current byte is not I64/Long");
    }
  }

  /** Read a {@code float} field, including tag, to the stream. */
  public function readFloat() {
    return $this->readFloatValue($this->readRawByte());
  }

  public function readFloatValue($tag) {
    switch ($tag & 0xf0) {  // or $tag, because float type > 0
 	  case Types::FLOAT: {
 	    return $this->readFloatLE(); // fixed32
 	  }
 	  default: throw new MismatchTypeException("Current byte is not Float");
    }
  }

  /** Read a {@code double} field, including tag, to the stream. */
  public function readDouble() {
    return $this->readDoubleValue($this->readRawByte());
  }

  public function readDoubleValue($tag) {
    switch ($tag) {
 	  case Types::DOUBLE: {
 	    return $this->readDoubleLE(); // fixed64
 	  }
 	  default: throw new MismatchTypeException("Current byte is not Double");
    }
  }

  /** Read a {@code string} field, including tag, to the stream. */
  public function readString() {
    return $this->readStringValue($this->readRawByte());
  }

  public function readStringValue($tag) {
    switch (ThinUtil::toByte($tag & 0xf0)) {  // reference type is negative number
 	  case Types::STRING: {
 	    $size = $this->computeRefSize($tag);
 	    if ($size == 0) {
 		  return "";
 	    }
 	    $len = $this->getNumber($size, false);
 	    //$bytes = $this->readRawBytes($len);
 	    $str = substr($this->input, $this->position, $len);
 	    $this->position += $len;
 	    return $str;
 	  }
 	  default: throw new MismatchTypeException("Current byte is not String");
    }
  }

  /** Read a {@code byte array} field, including tag, to the stream. */
  public function readBytes() {
    return $this->readBytesValue($this->readRawByte());
  }

  public function readBytesValue($tag) {
    switch (ThinUtil::toByte($tag & 0xf0)) {  // reference type is negative number
  	case Types::BYTES: {
  	  $size = $this->computeRefSize($tag);
  	  if ($size == 0) {
  		return array();
  	  }
  	  $len = $this->getNumber($size, false);
  	  return $this->readRawBytes($len);
  	}
  	default: throw new MismatchTypeException("Current byte is not ByteArray");
    }
  }

  /** Read a {@code collection} field, including tag, to the stream. */
  public function readCollection() {
    return $this->readCollectionValue($this->readRawByte());
  }

  public function readCollectionValue($tag) {
    switch (ThinUtil::toByte($tag & 0xf0)) {  // reference type is negative number
  	  case Types::LIST: {
  	    $size = $this->computeRefSize($tag);
  	    if ($size == 0) {
  		  return new TList();
  	    }

  	    $len = $this->getNumber($size, false);

  	    $collection = new TList();
  	    for ($i = 0; $i < $len; $i++) {
  	      $collection->add($this->getObject());
  	    }
  	    return $collection;
  	  }
  	  default: throw new MismatchTypeException(
  		  "Current byte is not Collection and collections only support List");
    }
  }

  /** Read a {@code map} field, including tag, to the stream. */
  public function readMap() {
    return $this->readMapValue($this->readRawByte());
  }

  public function readMapValue($tag) {
    switch (ThinUtil::toByte($tag & 0xf0)) {  // reference type is negative number
  	  case Types::MAP: {
  	    $size = $this->computeRefSize($tag);
  	    if ($size == 0) {
  		  return new TMap();
  	    }

  	    $len = $this->getNumber($size, false);

  	    $map = new TMap();
  	    for ($i = 0; $i < $len; $i++) {
  	      $map->put($this->getObject(), $this->getObject());
  	    }
  	    return $map;
  	  }
  	  default: throw new MismatchTypeException("Current byte is not Map");
    }
  }

  /** Read a {@code NULL} field, including tag, to the stream. */
  public function readNull() {
    return $this->readNullValue($this->readRawByte());
  }

  public function readNullValue($tag) {
    switch (ThinUtil::toByte($tag & 0xf0)) {
  	  case Types::NULL: return null;
  	  default: throw new MismatchTypeException("Current byte is not NULL");
    }
  }

  /** Read a {@code object} field, including tag, to the stream. */
  public function getObject() {
    $tag = $this->readRawByte();

    switch (ThinUtil::toByte($tag)) {
  	  case Types::BOOLEAN_TRUE: return true;
  	  case Types::BOOLEAN_FALSE: return false;
  	  case Types::NULL: return null;
    }

    switch (ThinUtil::toByte($tag & 0xf0)) {
  	  case Types::I32: return $this->readI32Value($tag);
  	  case Types::I64: return $this->readI64Value($tag);
  	  case Types::FLOAT: return $this->readFloatValue($tag);
  	  case Types::DOUBLE: return $this->readDoubleValue($tag);
  	  case Types::STRING: return $this->readStringValue($tag);
  	  case Types::BYTES: return $this->readBytesValue($tag);
  	  case Types::LIST: return $this->readCollectionValue($tag);
  	  case Types::MAP: return $this->readMapValue($tag);
  	  case Types::NULL: return $this->readNullValue($tag);
  	  default: throw new MismatchTypeException("type is not supported - " + $tag);
    }
  }

  /** Float.intBitsToFloat(readRawLittleEndian32()); */
  private function readFloatLE() {
    $value = 0;
    for ($i = 0; $i < 4; $i++) {
      $b = $this->readRawByte();
      $b = $b < 0 ? $b + 256 : $b;  // uint32(b)
      $value = $value | $b << ($i * 8);
    }
    return unpack('f*', pack('l', $value))[1];
  }

  /** Double.longBitsToDouble(readRawLittleEndian64()); */
  private function readDoubleLE() {
    $value = 0;
    for ($i = 0; $i < 8; $i++) {
      $b = $this->readRawByte();
      $b = $b < 0 ? $b + 256 : $b;  // uint32(b)
      $value = $value | $b << ($i * 8);
    }

    $upper = ($value >> 32) & 0xffffffff;
    $lower = $value & 0xffffffff;

    $real = ThinUtil::isLittleEndian()
            ? unpack('d*', pack('LL', $lower, $upper))[1]
            : unpack('d*', pack('LL', $upper, $lower))[1];
    return $real;
  }

  /**
   * read Number 32/64-bit from stream<br/>
   * little-endian
   */
  public function getNumber($size, $isNeg) {
    if ($size > 8 || $size < 1) {
      throw new TIllegalArgumentException("Int byte length must be in the range of 1-4");
    }
    $x = 0;
    $value = $this->readRawByte() & 0xff;
    for ($i = 1; $i < $size; $i++) {
 	  $x = ($x << 8) | 0xff;
 	  $value = (($this->readRawByte() & 0xff) << ($i * 8)) | ($value & $x); // note: 0xffL
    }
    return $isNeg ? -$value - 1 : $value;
  }

  /**
   * Compute number size<br/>
   * 000 = 1, 001 = 2, ..., 111 = 8
   */
  public function computeNumberSize($tag) {
    switch (($tag & 0x7)) {
 	  case 0 : return 1;
 	  case 1 : return 2;
 	  case 2 : return 3;
 	  case 3 : return 4;
 	  case 4 : return 5;
 	  case 5 : return 6;
 	  case 6 : return 7;
 	  default : return 8;
    }
  }

  /**
   * Compute reference size<br/>
   * 000 = 0, 001 = 1, ..., 100 = 4
   */
  public function computeRefSize($tag) {
    switch (($tag & 0x7)) {
  	  case 0 : return 0;
  	  case 1 : return 1;
  	  case 2 : return 2;
  	  case 3 : return 3;
  	  default : return 4;
    }
  }
}

/**
 * List类型
 *
 * @author Chunlin Jing
 */
class TList
{
  // size, isEmpty, contains, indexOf, [lastIndexOf],
  // toArray, get, set, add/insert, remove,
  // addAll/insertAll, [removeAll], clear, ...

  // The array buffer into which the elements of the ArrayList are stored.
  // The capacity of the ArrayList is the length of this array buffer.
  private $elementData = array();

  // The size of the TList (the number of elements it contains).
  private $size = 0;

  public function __construct($c = null) {
    if (is_null($c)) {
      return;
    }

    $element = null;
    if (is_array($c)) {
      $element = $c;
    } else if ($c instanceof TList) {
      $element = $c->elementData;
    } else {
      throw new TIllegalArgumentException("Value must be array() or TList");
    }

    // Avoid indexes not numbers
    foreach ($element as $key => $value) {
      $this->elementData[$this->size++] = $value;
    }
  }

  // Returns the number of elements in this list.
  public function size() {
    return $this->size;
  }

  // Returns <tt>true</tt> if this list contains no elements.
  public function isEmpty() {
    return $this->size == 0;
  }

  // Returns <tt>true</tt> if this collection contains the specified element.
  public function contains($o) {
    return in_array($o, $this->elementData, true);
  }

  // Returns the index of the first occurrence of the specified element
  // in this list, or -1 if this list does not contain the element.
  public function indexOf($o) {
    $index = array_search($o, $this->elementData, true);
    return is_bool($index) ? -1 : $index;
  }

  // Returns the element at the specified position in this list.
  public function get($index) {
    $this->rangeCheck($index);

    return $this->elementData[$index];
  }

  // Replaces the element at the specified position in this list with
  // the specified element.
  public function set($index, $element) {
    $this->rangeCheck($index);

    $oldValue = $this->elementData[$index];
    $this->elementData[$index] = $element;
    return $oldValue;
  }

  // Appends the specified element to the end of this list.
  public function add($element) {
    // PHP is a dynamic array, so there is no need to ensure capacity
    $this->elementData[$this->size++] = $element;
  }

  // Inserts the specified element at the specified position in this list.
  public function insert($index, $element) {
    $this->rangeCheckForAdd($index);

    if ($index == 0) {
      $newElementData = array($element);
      ThinUtil::arraycopy($this->elementData, 0, $newElementData, 1, $this->size);
      $this->elementData = $newElementData;
      $this->size++;
    } else if ($index == $this->size) {
      $this->elementData[$this->size++] = $element;
    } else {
      $newElementData = array();
      ThinUtil::arraycopy($this->elementData, 0, $newElementData, 0, $index);
      $newElementData[$index] = $element;
      ThinUtil::arraycopy($this->elementData, $index, $newElementData, $index + 1, $this->size - $index);
      $this->elementData = $newElementData;
      $this->size++;
    }
  }

  // Removes the element at the specified position in this list.
  // Shifts any subsequent elements to the left (subtracts one from their indices).
  public function remove($index) {
    $this->rangeCheck($index);

    $oldValue = $this->elementData[$index];

    $numMoved = $this->size - $index - 1;
    if ($numMoved > 0) {
      ThinUtil::arraycopy($this->elementData, $index + 1, $this->elementData, $index, $numMoved);
    }
    $this->elementData[--$this->size] = null; // clear to let GC do its work

    return $oldValue;
  }

  // Appends all of the elements in the specified collection to the end of this list.
  public function addAll($c) {
    $element = null;
    if (is_array($c)) {
      $element = $c;
    } else if ($c instanceof TList) {
      $element = $c->elementData;
    } else {
      throw new TIllegalArgumentException("Parameter must be array() or List");
    }
    foreach ($element as $key => $value) {
      $this->elementData[$this->size++] = $value;
    }
  }

  // Inserts all of the elements in the specified collection into this
  // list, starting at the specified position.
  public function insertAll($index, $c) {
    $this->rangeCheckForAdd($index);

    $element = null;
    $size = 0;
    if (is_array($c)) {
      $element = $c;
      $size = count($c);
    } else if ($c instanceof TList) {
      $element = $c->elementData;
      $size = $c->size;
    } else {
      throw new TIllegalArgumentException("Parameter must be array() or List");
    }

    if ($size == 0) {
      return;
    }

    if ($index == 0) {
      $newElementData = array();
      $i = 0;
      foreach ($element as $key => $value) {
        $newElementData[$i++] = $value;
      }
      ThinUtil::arraycopy($this->elementData, 0, $newElementData, $i, $this->size);
      $this->elementData = $newElementData;
      $this->size += $i;
    } else if ($index == $this->size) {
      foreach ($element as $key => $value) {
        $this->elementData[$this->size++] = $value;
      }
    } else {
      $newElementData = array();
      ThinUtil::arraycopy($this->elementData, 0, $newElementData, 0, $index);

      $i = $index;
      foreach ($element as $key => $value) {
        $newElementData[$i++] = $value;
      }

      ThinUtil::arraycopy($this->elementData, $index, $newElementData, $index + $i, $this->size - $index);
      $this->elementData = $newElementData;
      $this->size += ($i - $index);
    }
  }

  // Removes all of the elements from this list.
  // The list will be empty after this call returns.
  public function clear() {
    $this->elementData = array();
    $this->size = 0;
  }

  // Returns an array containing all of the elements in this list
  // in proper sequence (from first to last element).
  public function toArray() {
    $a = array();
    ThinUtil::arraycopy($this->elementData, 0, $a, 0, $this->size);
    return $a;
  }

  private function rangeCheck($index) {
    if ($index >= $this->size) {
      throw new TIndexOutOfBoundsException("Index: ".$index.", Size: ".$this->size);
    }
  }

  // A version of rangeCheck used by add(i, e) and addAll(i, e).
  private function rangeCheckForAdd($index) {
    if ($index > $this->size || $index < 0) {
      throw new TIndexOutOfBoundsException("Index: ".$index.", Size: ".$this->size);
    }
  }

  // Override
  public function __toString() {
    $str = "[";
    if ($this->size == 0) {
      return $str."]";
    }
    $e = $this->elementData[0];
    $e = is_bool($e) ? ($e ? "true" : "false") : $e;
    $str .= $e;
    for ($i = 1, $j = $this->size; $i < $j; $i++) {
      $e = $this->elementData[$i];
      $e = is_bool($e) ? ($e ? "true" : "false") : $e;
      $str .= ", ".$e;
    }
    return $str."]";
  }
}

/**
 * Map类型
 *
 * @author Chunlin Jing
 */
class TMap
{
  // size, isEmpty, keySet, values, entrySet,
  // get, put, putAll, remove, clear,
  // containsKey, containsValue, ...

  // The table, initialized on first use, and resized as necessary.
  private $table = array();

  // The number of key-value mappings contained in this map.
  private $size = 0;

  // Returns the number of key-value mappings in this map.
  public function size() {
    return $this->size;
  }

  // Returns <tt>true</tt> if this map contains no key-value mappings.
  public function isEmpty() {
    return $this->size == 0;
  }

  // Returns a {@link Set} view of the keys contained in this map.
  public function keySet() {
    return array_keys($this->table);
  }

  // This implementation returns a collection that values.
  public function values() {
    return array_values($this->table);
  }

  // Returns a {@link Set} view of the mappings contained in this map.
  public function entrySet() {
    $es = array();
    foreach ($this->table as $key => $value) {
      $es[$key] = $value;
    }
    return $es;
  }

  // Returns the value to which the specified key is mapped,
  // or {@code null} if this map contains no mapping for the key.
  public function get($key) {
    return $this->table[$key];
  }

  // Associates the specified value with the specified key in this map.
  // If the map previously contained a mapping for the key, the old value is replaced.
  public function put($key, $value) {
    if (is_null($key)) {
      throw new TIllegalArgumentException("Key cant not be null");
    }
    $oldValue = null;
    if (array_key_exists($key, $this->table)) {
      $oldValue = $this->table[$key];
    } else {
      $this->size++;
    }
    $this->table[$key] = $value;
    return $oldValue;
  }

  // Copies all of the mappings from the specified map to this map.
  public function putAll($m) {
    if (is_null($m) || !($m instanceof TMap)) {
      throw new TIllegalArgumentException("Parameter must be Map");
    }
    foreach ($m->table as $key => $value) {
      $this->table[$key] = $value;
    }
    $this->size = count($this->table);
  }

  // Removes the mapping for the specified key from this map if present.
  public function remove($key) {
    $oldValue = null;
    if (array_key_exists($key, $this->table)) {
      $oldValue = $this->table[$key];
      unset($this->table[$key]);
      $this->size--;
    }
    return $oldValue;
  }

  // Removes all of the mappings from this map.
  // The map will be empty after this call returns.
  public function clear() {
    $this->table = array();
    $this->size = 0;
  }

  // Returns <tt>true</tt> if this map contains a mapping for the specified key.
  public function containsKey($key) {
    // Warning: array_key_exists(): The first argument should be either a string or an integer
    return array_key_exists($key, $this->table);
  }

  // Returns <tt>true</tt> if this map maps one or more keys to the specified value.
  public function containsValue($value) {
    $key = array_search($value, $this->table, true);
    return is_null($key);
  }

  // Override
  public function __toString() {
    $str = "{";
    if ($this->size == 0) {
      return $str."}";
    }
    foreach ($this->table as $key => $value) {
      $value = is_bool($value) ? ($value ? "true" : "false") : $value;
      $str .= $key."=".$value.", ";
    }
    $str = rtrim($str, ", ");
    return $str."}";
  }
}

/**
 * 数字类型. 可用于明确数值序列化采用的类型.
 *
 * @author Chunlin Jing
 */
class TNumber
{
  public const INT = 4;
  public const LONG = 8;
  public const FLOAT = 16;
  public const DOUBLE = 32;

  private $number = 0;
  private $type = TNumber::INT;
  
  public function __construct($number, $type = TNumber::DOUBLE) {
    $this->number = $number;
    $this->type = $type;
  }

  public function getNumber() {
    return $this->number;
  }

  public function getType() {
    return $this->type;
  }

  // Override
  public function __toString() {
    return "number:".$this->number.", type:".$this->type;
  }
}
?>