<?php

class UsuarioDTO
{
    private ?int $id = null;
    private ?int $unidade_id = null;
    private ?string $unidade_identificacao = null;
    private string $nome = "";
    private string $cpf = "";
    private string $email = "";
    private string $telefone = "";
    private string $senha_hash = "";
    private string $perfil = "";
    private ?string $biometria_hash = null;
    private bool $termo_lgpd = false;
    private ?string $data_aceite_lgpd = null;
    private bool $ativo = true;

    // ID
    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    // Unidade
    public function getUnidadeId(): ?int
    {
        return $this->unidade_id;
    }

    public function setUnidadeId(?int $unidade_id): void
    {
        $this->unidade_id = $unidade_id;
    }

    public function getUnidadeIdentificacao(): ?string
    {
        return $this->unidade_identificacao;
    }

    public function setUnidadeIdentificacao(?string $unidade_identificacao): void
    {
        $this->unidade_identificacao = $unidade_identificacao;
    }

    // Nome
    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): void
    {
        $this->nome = $nome;
    }

    // CPF
    public function getCpf(): string
    {
        return $this->cpf;
    }

    public function setCpf(string $cpf): void
    {
        $this->cpf = $cpf;
    }

    // E-mail
    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    // Telefone / WhatsApp
    public function getTelefone(): string
    {
        return $this->telefone;
    }

    public function setTelefone(string $telefone): void
    {
        $this->telefone = $telefone;
    }

    // Senha
    public function getSenhaHash(): string
    {
        return $this->senha_hash;
    }

    public function setSenhaHash(string $senha_hash): void
    {
        $this->senha_hash = $senha_hash;
    }

    // Perfil
    public function getPerfil(): string
    {
        return $this->perfil;
    }

    public function setPerfil(string $perfil): void
    {
        $this->perfil = $perfil;
    }

    // Biometria
    public function getBiometriaHash(): ?string
    {
        return $this->biometria_hash;
    }

    public function setBiometriaHash(?string $biometria_hash): void
    {
        $this->biometria_hash = $biometria_hash;
    }

    // Termo LGPD
    public function getTermoLgpd(): bool
    {
        return $this->termo_lgpd;
    }

    public function setTermoLgpd(bool $termo_lgpd): void
    {
        $this->termo_lgpd = $termo_lgpd;
    }

    // Data aceite LGPD
    public function getDataAceiteLgpd(): ?string
    {
        return $this->data_aceite_lgpd;
    }

    public function setDataAceiteLgpd(?string $data_aceite_lgpd): void
    {
        $this->data_aceite_lgpd = $data_aceite_lgpd;
    }

    // Ativo
    public function getAtivo(): bool
    {
        return $this->ativo;
    }

    public function setAtivo(bool $ativo): void
    {
        $this->ativo = $ativo;
    }
}