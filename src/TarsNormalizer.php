<?php


namespace wenbinye\tars\call;


use kuiper\reflection\ReflectionType;
use kuiper\tars\type\MapType;
use kuiper\tars\type\StructMap;
use kuiper\tars\type\StructType;
use kuiper\tars\type\Type;
use kuiper\tars\type\VectorType;
use Webmozart\Assert\Assert;

class TarsNormalizer
{
    /**
     * 转换数据类型为 php 原生数据类型
     * @param $data
     * @param Type $type
     */
    public function normalize($data, Type $type)
    {
        if ($data === null) {
            return null;
        }
        if ($type->isPrimitive()) {
            return $data;
        }
        if ($type->isEnum()) {
            return is_object($data) ? $data->name() : $data;
        }
        if ($type->isMap()) {
            return $this->convertMap($data, $type->asMapType());
        }
        if ($type->isVector()) {
            return $this->convertVector($data, $type->asVectorType());
        }
        if ($type->isStruct()) {
            return $this->convertStruct($data, $type->asStructType());
        }
        throw new \InvalidArgumentException("unknown type " . $type);
    }

    private function convertMap($data, MapType $type)
    {
        if ($type->getKeyType()->isPrimitive()) {
            $ret = [];
            foreach ($data as $key => $value) {
                $ret[$this->normalize($key, $type->getKeyType())] = $this->normalize($value, $type->getValueType());
            }
        } else {
            $ret = [];
            foreach ($data as $item) {
                $ret[] = [
                    $this->normalize($item[0], $type->getKeyType()),
                    $this->normalize($item[1], $type->getValueType())
                ];
            }
        }
        return $ret;
    }

    private function convertVector($data, VectorType $type)
    {
        if ($type->getSubType()->isPrimitive() && ((string) $type->getSubType()->asPrimitiveType()) === 'byte') {
            Assert::string($data);
            return base64_encode($data);
        }
        Assert::isArray($data);

        $ret = [];
        foreach ($data as $item) {
            $ret[] = $this->normalize($item, $type->getSubType());
        }
        return $ret;
    }

    private function convertStruct($data, StructType $type)
    {
        $ret = [];
        foreach ($type->getFields() as $field) {
            if (isset($data->{$field->getName()})) {
                $ret[$field->getName()] = $this->normalize($data->{$field->getName()}, $field->getType());
            }
        }
        return $ret;
    }

    /**
     * 转换原生数据类型为对应 php 类型
     * @param $data
     * @param Type $type
     * @return mixed|void|null
     */
    public function denormalize($data, Type $type)
    {
        if ($data === null) {
            return null;
        }
        if ($type->isPrimitive()) {
            return ReflectionType::parse($type->asPrimitiveType()->getPhpType())->sanitize($data);
        }
        if ($type->isEnum()) {
            if (is_int($data)) {
                return call_user_func([$type->asEnumType()->getClassName(), 'fromValue'], $data);
            } else {
                return call_user_func([$type->asEnumType()->getClassName(), 'fromName'], $data);
            }
        }
        if ($type->isMap()) {
            return $this->toMap($data, $type->asMapType());
        }
        if ($type->isVector()) {
            return $this->toVector($data, $type->asVectorType());
        }
        if ($type->isStruct()) {
            return $this->toStruct($data, $type->asStructType());
        }
        throw new \InvalidArgumentException("unknown type " . $type);
    }

    private function toMap($data, MapType $type)
    {
        if ($type->getKeyType()->isPrimitive()) {
            $ret = [];
            foreach ($data as $key => $value) {
                $ret[$this->denormalize($key, $type->getKeyType())] = $this->denormalize($value, $type->getValueType());
            }
        } else {
            $ret = new StructMap();
            foreach ($data as $item) {
                $ret->put(
                    $this->denormalize($item[0], $type->getKeyType()),
                    $this->denormalize($item[1], $type->getValueType())
                );
            }
        }
        return $ret;
    }

    private function toVector($data, VectorType $type)
    {
        if ($type->getSubType()->isPrimitive() && ((string) $type->getSubType()->asPrimitiveType()) === 'byte') {
            Assert::string($data);
            $ret = base64_decode($data);
            if ($ret === false) {
                throw new \InvalidArgumentException("must base64 encoded");
            }
            return $ret;
        }
        Assert::isArray($data);

        $ret = [];
        foreach ($data as $item) {
            $ret[] = $this->denormalize($item, $type->getSubType());
        }
        return $ret;
    }

    private function toStruct($data, StructType $type)
    {
        $class = $type->getClassName();
        $ret = new $class;
        foreach ($type->getFields() as $field) {
            if (isset($data[$field->getName()])) {
                $ret->{$field->getName()} = $this->denormalize($field->getType(), $data[$field->getName()]);
            }
        }
        return $ret;
    }
}