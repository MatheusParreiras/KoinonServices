<?php

class ReservaDTO
{
    private ?int $id = null;
    private ?int $usuario_id = null;
    private ?int $local_reserva_id = null;
    private ?string $usuario_nome = null;

    private string $espaco_nome = "";
    /** @var string[] nomes dos convidados (lista de presença) */
    private array $lista_presenca = [];

    private ?string $data_inicio = null;
    private ?string $data_fim = null;

    private string $status = "Pendente";
    private float $valor_taxa = 0.00;

    /*
     * Status financeiro da reserva.
     *
     * Valores esperados:
     * - null
     * - Pendente
     * - Pago
     * - Vencido
     * - Cancelado
     */
    private ?string $pagamento_status = null;

    private ?string $created_at = null;


    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }


    public function getUsuarioId(): ?int
    {
        return $this->usuario_id;
    }

    public function setUsuarioId(?int $usuario_id): void
    {
        $this->usuario_id = $usuario_id;
    }


    public function getUsuarioNome(): ?string
    {
        return $this->usuario_nome;
    }

    public function setUsuarioNome(?string $usuario_nome): void
    {
        $this->usuario_nome = $usuario_nome;
    }


    public function getLocalReservaId(): ?int
    {
        return $this->local_reserva_id;
    }

    public function setLocalReservaId(?int $local_reserva_id): void
    {
        $this->local_reserva_id = $local_reserva_id;
    }


    public function getEspacoNome(): string
    {
        return $this->espaco_nome;
    }

    public function setEspacoNome(string $espaco_nome): void
    {
        $this->espaco_nome = $espaco_nome;
    }


    /**
     * Lista de presença: nomes dos convidados da reserva.
     */
    public function getListaPresenca(): array
    {
        return $this->lista_presenca;
    }

    public function setListaPresenca(array $lista): void
    {
        $this->lista_presenca = self::normalizarLista($lista);
    }

    /** Compatibilidade: número de convidados na lista. */
    public function getQuantidadePessoas(): int
    {
        return count($this->lista_presenca);
    }

    /** Converte o texto do formulário (uma linha por convidado) em lista. */
    public static function listaDeTexto(?string $texto): array
    {
        $partes = preg_split('/[\r\n;]+/', (string) $texto) ?: [];
        return self::normalizarLista($partes);
    }

    /** Converte o JSON salvo no banco em lista. */
    public static function listaDeJson(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $dados = json_decode($json, true);
        return is_array($dados) ? self::normalizarLista($dados) : [];
    }

    public static function listaParaJson(array $lista): string
    {
        return json_encode(
            self::normalizarLista($lista),
            JSON_UNESCAPED_UNICODE
        );
    }

    private static function normalizarLista(array $lista): array
    {
        $saida = [];
        foreach ($lista as $item) {
            $nome = trim(preg_replace('/\s+/', ' ', (string) $item));
            if ($nome === '') {
                continue;
            }
            $nome = mb_substr($nome, 0, 100);
            $saida[mb_strtolower($nome)] = $nome;
        }
        return array_values($saida);
    }


    public function getDataInicio(): ?string
    {
        return $this->data_inicio;
    }

    public function setDataInicio(?string $data_inicio): void
    {
        $this->data_inicio = $data_inicio;
    }


    public function getDataFim(): ?string
    {
        return $this->data_fim;
    }

    public function setDataFim(?string $data_fim): void
    {
        $this->data_fim = $data_fim;
    }


    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }


    public function getValorTaxa(): float
    {
        return $this->valor_taxa;
    }

    public function setValorTaxa(float $valor_taxa): void
    {
        $this->valor_taxa = $valor_taxa;
    }


    public function getPagamentoStatus(): ?string
    {
        return $this->pagamento_status;
    }

    public function setPagamentoStatus(?string $pagamento_status): void
    {
        $this->pagamento_status = $pagamento_status;
    }


    public function getCreatedAt(): ?string
    {
        return $this->created_at;
    }

    public function setCreatedAt(?string $created_at): void
    {
        $this->created_at = $created_at;
    }
}